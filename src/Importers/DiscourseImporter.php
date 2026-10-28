<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Discourse (PostgreSQL) → Flarum.
 *   categories → tags · users → users · topics → discussions · posts → posts
 * Uses Discourse's already-rendered `cooked` HTML (sanitised). Passwords are
 * PBKDF2 (not portable) so members reset. Needs the pdo_pgsql PHP extension.
 */
class DiscourseImporter
{
    private static function cfg(array $cfg): array
    {
        $cfg['driver'] = ($cfg['driver'] ?? '') ?: 'pgsql';

        return $cfg;
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect(self::cfg($cfg));
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'topics', 'posts', 'categories'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException(Src::t('not_source.discourse', ['table' => $req]));
            }
        }

        return ['ok' => true, 'counts' => [
            // Staged users are addresses that only ever emailed in: not members.
            'users' => (int) $conn->table('users')->where('id', '>', 0)->when($sb->hasColumn('users', 'staged'), fn ($q) => $q->where('staged', false))->count(),
            'categories' => (int) $conn->table('categories')->count(),
            'topics' => (int) self::topics($conn)->count(),
            'posts' => (int) self::posts($conn->table('posts'))->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $rawCfg): array
    {
        $cfg = self::cfg($rawCfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase(
                'tags',
                'categories',
                fn () => $hasTags ? (int) Src::connect($cfg)->table('categories')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    /*
                     * 🚨 Every category, once per batch (a site has dozens). A
                     * subcategory came across as a top-level tag and a
                     * staff-only (read_restricted) category open to everyone. A
                     * subcategory is filed under its root category now — Flarum
                     * nests one level, so a third level folds into the root.
                     * The old addresses are /c/slug and /c/parent/child: both
                     * are mapped for the redirects.
                     */
                    $cats = $ctx->src()->table('categories')->get()->keyBy('id');
                    $chain = function (int $id) use ($cats): array {
                        $up = [];
                        for (; ($c = $cats->get($id)) && ! isset($up[$id]); $id = (int) ($c->parent_category_id ?? 0)) {
                            $up[$id] = $c;
                        }

                        return array_reverse(array_values($up)); // root first
                    };
                    $make = fn ($c, ?int $parent) => Dst::tag($c->name ?: 'Category', Src::tagSlug($c->name ?: 'category', (int) $c->id), $c->description ?? null, $c->color ?? null, (int) ($c->position ?? 0), $parent, ! empty($c->read_restricted));
                    $rows = $cats->filter(fn ($c) => (int) $c->id > (int) $cursor)->sortBy('id')->take($limit)->values();
                    $parents = [];
                    foreach ($rows as $c) {
                        if (($top = $chain((int) $c->id)[0] ?? null) && (int) $top->id !== (int) $c->id) {
                            $parents[$c->id] = $top;
                        }
                    }
                    $known = $ctx->mapGet('tag', array_map(fn ($t) => $t->id, $parents));
                    $map = $made = $slugs = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->id;
                        $parent = null;
                        if ($top = $parents[$c->id] ?? null) {
                            // A parent with a higher id than its child is made now; its
                            // own row later finds it by slug and maps it then.
                            $parent = $map[$top->id] ?? $known[(string) $top->id] ?? ($made[$top->id] ??= $make($top, null));
                        }
                        $map[$c->id] = $make($c, $parent);
                        if (($slug = (string) ($c->slug ?? '')) !== '') {
                            $slugs[$slug] = $map[$c->id];
                            $slugs[implode('/', array_map(fn ($x) => (string) $x->slug, $chain((int) $c->id)))] = $map[$c->id];
                        }
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);
                    $ctx->mapPut('tag-slug', $slugs);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table('users')->where('id', '>', 0)->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $conn = $ctx->src();
                    $sb = $conn->getSchemaBuilder();
                    $rows = $conn->table('users')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    // Email lives in user_emails (newer) or users.email (older).
                    $emailByUser = [];
                    if (count($rows) && $sb->hasTable('user_emails')) {
                        foreach ($conn->table('user_emails')->whereIn('user_id', $rows->pluck('id')->all())->orderByDesc('primary')->get(['user_id', 'email']) as $e) {
                            $emailByUser[(string) $e->user_id] ??= $e->email;
                        }
                    }
                    $hasInlineEmail = $sb->hasColumn('users', 'email');
                    // The PBKDF2 hash: user_passwords since 2024, users before.
                    $pw = [];
                    if (count($rows) && $sb->hasTable('user_passwords')) {
                        foreach ($conn->table('user_passwords')->whereIn('user_id', $rows->pluck('id')->all())->get(['user_id', 'password_hash', 'password_salt', 'password_algorithm']) as $r) {
                            $pw[(string) $r->user_id] = [$r->password_hash, $r->password_salt, $r->password_algorithm];
                        }
                    }
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->id;
                        $email = trim((string) ($emailByUser[(string) $u->id] ?? ($hasInlineEmail ? ($u->email ?? '') : '')));
                        /*
                         * 🚨 Every account arrived confirmed and free to post: a
                         * staged user (an address that only ever emailed in,
                         * which never chose a password), one that never
                         * activated, and a suspended member.
                         */
                        $staged = ! empty($u->staged);
                        $confirmed = ! $staged && (bool) ($u->active ?? true);
                        $until = ! empty($u->suspended_till) ? Src::ts($u->suspended_till) : null;
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        try {
                            [$hash, $salt, $algo] = $pw[(string) $u->id] ?? [$u->password_hash ?? null, $u->salt ?? null, $u->password_algorithm ?? null];
                            $legacy = $hash && ! $staged ? ['scheme' => 'discourse', 'hash' => (string) $hash, 'salt' => (string) $salt.'|'.(string) $algo] : null;
                            $map[$u->id] = Dst::user(Src::username($u->username ?? ($u->name ?? null), (int) $u->id), $email, null, Src::ts($u->created_at ?? null), null, $legacy, $confirmed, $until, display: $u->username ?? ($u->name ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            new Phase(
                'topics',
                'topics',
                fn () => (int) self::topics(Src::connect($cfg))->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = self::topics($ctx->src())->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('category_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('id'));
                    foreach ($rows as $t) {
                        $cursor = $t->id;
                        if (isset($known[(string) $t->id])) {
                            continue;
                        }
                        // Closed or archived: locked. Unlisted (visible false): hidden.
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->created_at ?? null), ! empty($t->pinned_at),
                            ! empty($t->closed) || ! empty($t->archived), isset($t->visible) && ! $t->visible);
                        $map[$t->id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->category_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->category_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                'posts',
                'topic_id',
                'id',
                fn () => (int) self::posts(Src::connect($cfg)->table('posts'))->count(),
                fn ($post) => [
                    'tid' => (int) $post->topic_id, 'pid' => (int) $post->id, 'uid' => $post->user_id,
                    'html' => Src::sanitizeHtml($post->cooked ?? ''), 'at' => Src::ts($post->created_at ?? null), 'ok' => true,
                ],
                fn ($q) => self::posts($q),
                files: self::attachments(),
            ),
        ], Phases::tail());
    }

    /**
     * Groups: `groups` and `group_users`. The automatic ones are Discourse's
     * own: 0 everyone, 1 admins, 2 moderators, 3 staff (both of those), 10–14
     * the trust levels, which Discourse fills by itself.
     */
    public static function groups(array $cfg): array
    {
        return [
            'needs' => ['groups', 'group_users'],
            'groups' => fn (Ctx $ctx) => $ctx->src()->table('groups')->orderBy('id')->get()->map(fn ($g) => [
                'id' => (string) $g->id, 'name' => trim((string) ($g->full_name ?? '')) ?: (string) $g->name, 'color' => $g->flair_bg_color ?? null,
                'role' => match ((int) $g->id) {
                    0 => 'member', 1 => 'admin', 2 => 'mod',
                    default => ! empty($g->automatic) ? 'automatic' : null,
                },
            ])->all(),
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::pivot($ctx->src()->table('users'), 'id', $after, $limit,
                fn (array $ids) => $ctx->src()->table('group_users')->whereIn('user_id', $ids)->whereNotIn('group_id', [0, 3, 10, 11, 12, 13, 14])->get()->map(fn ($r) => [$r->user_id, $r->group_id])),
        ];
    }

    /**
     * Profile photos: `uploaded_avatar_id` → `uploads.url`. Birthdays:
     * `users.date_of_birth` (or the birthday plugin's custom field), always
     * in 1904 — Discourse keeps no year.
     */
    public static function profiles(array $cfg): array
    {
        return [
            'count' => fn () => (int) Src::connect(self::cfg($cfg))->table('users')->where('id', '>', 0)->count(),
            'fetch' => function (int $after, int $limit, Ctx $ctx) {
                $conn = $ctx->src();
                $sb = $conn->getSchemaBuilder();
                $page = Profiles::page($conn->table('users'), 'id', $after, $limit, fn ($u) => ['avatar_id' => $u->uploaded_avatar_id ?? null, 'dob' => $u->date_of_birth ?? null]);
                $uploads = ($ids = array_filter(array_column($page['profiles'], 'avatar_id'))) ? $conn->table('uploads')->whereIn('id', $ids)->pluck('url', 'id')->all() : [];
                $dobs = $sb->hasTable('user_custom_fields') && $page['profiles']
                    ? $conn->table('user_custom_fields')->whereIn('user_id', array_column($page['profiles'], 'uid'))->where('name', 'date_of_birth')->pluck('value', 'user_id')->all() : [];
                foreach ($page['profiles'] as &$p) {
                    $url = (string) ($uploads[(int) $p['avatar_id']] ?? '');
                    $p['avatar'] = $url === '' ? null : ['url' => $url] + (preg_match('#/(uploads/.+)$#', (string) parse_url($url, PHP_URL_PATH), $m) ? ['root' => 'public', 'path' => $m[1]] : []);
                    $b = Profiles::date((string) ($p['dob'] ?: ($dobs[$p['uid']] ?? '')));
                    $p['birthday'] = $b ? [null, $b[1], $b[2]] : null;
                }
                unset($p);

                return $page;
            },
        ];
    }

    /**
     * Uploads a post holds (`upload_references`, or `post_uploads` before
     * it): the cooked text links each by address, or by its short form
     * (upload://<base62 sha1>, /uploads/short-url/…), and every form is
     * matched. Files under public/uploads/ in the Discourse folder.
     */
    public static function attachments(): array
    {
        return ['fetch' => function (array $ids, Ctx $ctx) {
            $conn = $ctx->src();
            $sb = $conn->getSchemaBuilder();
            $q = match (true) {
                $sb->hasTable('upload_references') => $conn->table('upload_references as r')->where('r.target_type', 'Post')->whereIn('r.target_id', $ids)->select('r.target_id as post_id'),
                $sb->hasTable('post_uploads') => $conn->table('post_uploads as r')->whereIn('r.post_id', $ids)->select('r.post_id'),
                default => null,
            };
            $out = [];
            foreach ($q ? $q->join('uploads as u', 'u.id', '=', 'r.upload_id')->addSelect('u.id', 'u.url', 'u.original_filename', 'u.sha1', 'u.extension', 'u.user_id')->orderBy('u.id')->get() : [] as $u) {
                $short = Attachments::base62((string) $u->sha1).($u->extension ? '.'.$u->extension : '');
                $path = ltrim((string) parse_url((string) $u->url, PHP_URL_PATH), '/');
                $out[$u->post_id][] = [
                    'id' => (string) $u->id, 'name' => (string) $u->original_filename, 'uid' => $u->user_id, 'url' => (string) $u->url,
                    'append' => false, 'keys' => [(string) $u->url, $path, 'upload://'.$short, 'uploads/short-url/'.$short],
                ] + (str_starts_with($path, 'uploads/') ? ['root' => 'public', 'path' => $path] : []);
            }

            return $out;
        }, 'inline' => '#/uploads/#', 'disk' => ['#^.*?/uploads/#', 'public/uploads']];
    }
    /**
     * The topics that are discussions. 🚨 Each category's "About the X
     * category" topic (categories.topic_id) is the category's description,
     * not a conversation: it arrived as a discussion in every tag.
     */
    private static function topics(ConnectionInterface $conn): Builder
    {
        $q = $conn->table('topics')->whereNull('deleted_at')->where('archetype', 'regular');

        return $conn->getSchemaBuilder()->hasColumn('categories', 'topic_id')
            ? $q->whereNotIn('id', fn ($c) => $c->from('categories')->whereNotNull('topic_id')->select('topic_id'))
            : $q;
    }

    /**
     * The posts that are replies: not deleted, not whispers or small actions,
     * and 🚨 not hidden by flags (posts.hidden) — those arrived visible to all.
     * Skipped rather than hidden: the shared posts phase writes visible comments.
     */
    private static function posts(Builder $q): Builder
    {
        $q->whereNull('deleted_at')->where('post_type', 1);

        return $q->getConnection()->getSchemaBuilder()->hasColumn('posts', 'hidden') ? $q->where('hidden', false) : $q;
    }

    /**
     * Personal messages are topics with `archetype` 'private_message'; who is
     * in one is `topic_allowed_users` (a member who removes themselves is taken
     * off it) plus the members of any `topic_allowed_groups` group, and how far
     * each has read is `topic_users.last_read_post_number`. Messages the system
     * itself started (welcome messages, user id below 1) are not conversations
     * anybody had; nor are deleted ones, or whispers and small actions.
     */
    public static function privateMessages(array $rawCfg): array
    {
        $cfg = self::cfg($rawCfg);
        $pms = fn ($conn) => $conn->table('posts')->join('topics', 'topics.id', '=', 'posts.topic_id')
            ->where('topics.archetype', 'private_message')->whereNull('topics.deleted_at')->where('topics.user_id', '>', 0)
            ->whereNull('posts.deleted_at')->where('posts.post_type', 1);

        return [
            'count' => function () use ($cfg, $pms) {
                $conn = Src::connect($cfg);

                return $conn->getSchemaBuilder()->hasTable('topic_allowed_users') ? (int) $pms($conn)->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($pms) {
                $conn = $ctx->src();
                $sb = $conn->getSchemaBuilder();
                if (! $sb->hasTable('topic_allowed_users')) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $pms($conn)->where('posts.id', '>', $after)->orderBy('posts.id')->limit($limit)
                    ->get(['posts.id', 'posts.topic_id', 'posts.user_id', 'posts.cooked', 'posts.created_at', 'posts.post_number', 'topics.title']);
                $ids = $rows->pluck('topic_id')->unique()->values()->all();
                $who = $read = [];
                foreach ($ids ? $conn->table('topic_allowed_users')->whereIn('topic_id', $ids)->get(['topic_id', 'user_id']) : [] as $r) {
                    $who[$r->topic_id][] = $r->user_id;
                }
                if ($ids && $sb->hasTable('topic_allowed_groups') && $sb->hasTable('group_users')) {
                    foreach ($conn->table('topic_allowed_groups')->join('group_users', 'group_users.group_id', '=', 'topic_allowed_groups.group_id')
                        ->whereIn('topic_allowed_groups.topic_id', $ids)->get(['topic_allowed_groups.topic_id', 'group_users.user_id']) as $r) {
                        $who[$r->topic_id][] = $r->user_id;
                    }
                }
                if ($ids && $sb->hasTable('topic_users')) {
                    foreach ($conn->table('topic_users')->whereIn('topic_id', $ids)->get(['topic_id', 'user_id', 'last_read_post_number']) as $r) {
                        $read[$r->topic_id][$r->user_id] = (int) $r->last_read_post_number;
                    }
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->id;
                    $in = $who[$m->topic_id] ?? [];
                    $out[] = [
                        'id' => (string) $m->id,
                        'conv' => (string) $m->topic_id,
                        'thread' => (string) $m->topic_id,
                        'title' => (string) ($m->title ?? ''),
                        'uid' => $m->user_id,
                        'people' => $in,
                        'keep' => $in,
                        'unread' => array_filter($in, fn ($u) => ($read[$m->topic_id][$u] ?? 0) < (int) $m->post_number),
                        'html' => Src::sanitizeHtml($m->cooked ?? ''),
                        'at' => Src::ts($m->created_at ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * discourse-poll: a poll belongs to the post it was written in, so it
     * lands on that post. `type` 0 = one choice, 1 = several (up to `max`),
     * 2 = pick a number, 3 = ranked choice, which fof/polls cannot hold and
     * is skipped. Votes not tied to a member (`anonymous_votes`, left by an
     * earlier import into Discourse) count without a voter. The question is
     * the poll's title, else its topic's.
     */
    public static function polls(array $cfg): array
    {
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable('polls') && $conn->getSchemaBuilder()->hasTable('poll_options') && $conn->getSchemaBuilder()->hasTable('poll_votes');

        return [
            'count' => function () use ($cfg, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table('polls')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                [$rows, $cursor, $done, $n] = Polls::page($conn->table('polls'), 'id', $after, $limit, $ctx);
                $ids = $rows->pluck('id')->all();
                $posts = $ids ? $conn->table('posts')->join('topics', 'topics.id', '=', 'posts.topic_id')
                    ->whereIn('posts.id', $rows->pluck('post_id')->all())->get(['posts.id', 'posts.user_id', 'topics.title'])->keyBy('id') : collect();
                $votes = $named = [];
                foreach ($ids ? $conn->table('poll_votes')->whereIn('poll_id', $ids)->get(['poll_id', 'poll_option_id', 'user_id', 'created_at']) : [] as $v) {
                    $votes[$v->poll_id][] = [$v->user_id, (string) $v->poll_option_id, Polls::at($v->created_at)];
                    $named[$v->poll_option_id] = ($named[$v->poll_option_id] ?? 0) + 1;
                }
                $options = [];
                foreach ($ids ? $conn->table('poll_options')->whereIn('poll_id', $ids)->orderBy('id')->get(['id', 'poll_id', 'html', 'anonymous_votes']) : [] as $o) {
                    $options[$o->poll_id][(string) $o->id] = ['text' => Polls::plain((string) $o->html), 'count' => ($named[$o->id] ?? 0) + (int) ($o->anonymous_votes ?? 0)];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $post = $posts->get($poll->post_id);
                    $type = (int) ($poll->type ?? 0);
                    $at = Src::ts($poll->created_at ?? null);
                    $out[] = [
                        'id' => (string) $poll->id,
                        'post' => (string) $poll->post_id,
                        'question' => (string) (($poll->title ?? '') ?: ($post->title ?? '')),
                        'uid' => $post->user_id ?? null,
                        'at' => $at,
                        'ends' => Polls::at($poll->close_at ?? null)
                            ?? ((int) ($poll->status ?? 0) === 1 ? Polls::closedAt(Polls::at($poll->updated_at ?? null), $at) : null),
                        'max' => $type === 1 ? (int) ($poll->max ?? 0) : 1,
                        'public' => (int) ($poll->visibility ?? 0) === 1,
                        'change' => true,
                        // 2 = results shown once the poll has closed
                        'hide' => (int) ($poll->results ?? 0) === 2,
                        'options' => $type === 3 ? [] : ($options[$poll->id] ?? []),
                        'votes' => $votes[$poll->id] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
