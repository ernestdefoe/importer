<?php

namespace ErnestDefoe\Importer\Importers;

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
            'users' => (int) $conn->table('users')->where('id', '>', 0)->count(),
            'categories' => (int) $conn->table('categories')->count(),
            'topics' => (int) $conn->table('topics')->whereNull('deleted_at')->where('archetype', 'regular')->count(),
            'posts' => (int) $conn->table('posts')->whereNull('deleted_at')->where('post_type', 1)->count(),
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
                    $rows = $ctx->src()->table('categories')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->id;
                        $map[$c->id] = Dst::tag($c->name ?: 'Category', Src::tagSlug($c->name ?: 'category', (int) $c->id), $c->description ?? null, $c->color ?? null, (int) ($c->position ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

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
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        try {
                            [$hash, $salt, $algo] = $pw[(string) $u->id] ?? [$u->password_hash ?? null, $u->salt ?? null, $u->password_algorithm ?? null];
                            $legacy = $hash ? ['scheme' => 'discourse', 'hash' => (string) $hash, 'salt' => (string) $salt.'|'.(string) $algo] : null;
                            $map[$u->id] = Dst::user(Src::username($u->username ?? ($u->name ?? null), (int) $u->id), $email, null, Src::ts($u->created_at ?? null), null, $legacy);
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
                fn () => (int) Src::connect($cfg)->table('topics')->whereNull('deleted_at')->where('archetype', 'regular')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = $ctx->src()->table('topics')->whereNull('deleted_at')->where('archetype', 'regular')
                        ->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
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
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->created_at ?? null), ! empty($t->pinned_at));
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
                fn () => (int) Src::connect($cfg)->table('posts')->whereNull('deleted_at')->where('post_type', 1)->count(),
                fn ($post) => [
                    'tid' => (int) $post->topic_id, 'pid' => (int) $post->id, 'uid' => $post->user_id,
                    'html' => Src::sanitizeHtml($post->cooked ?? ''), 'at' => Src::ts($post->created_at ?? null), 'ok' => true,
                ],
                fn ($q) => $q->whereNull('deleted_at')->where('post_type', 1),
            ),
        ], Phases::tail());
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
