<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Convoro → Flarum (the reverse of Convoro's own Flarum importer).
 *   categories/forums → tags · users → users · topics → discussions · posts → posts.
 *
 * Convoro hashes with bcrypt, so passwords copy straight across and members keep
 * their logins. Post bodies are stored as rendered HTML, which runs through the
 * normal HTML → Markdown → formatter pipeline.
 *
 * 🚨 TWO schemas, and they are not compatible. Convoro 1.x kept forums in
 * `categories` with a `name`, and a post's HTML in `posts.body_html`. Convoro 2
 * renamed them to `forums` with a `title`, and `posts.content_html`. Detecting
 * which is in front of us is the whole of `layout()`, and it matters because
 * the 1.x-only version failed against Convoro 2 with "this doesn't look like a
 * Convoro database" — which is the least helpful thing it could have said about
 * a database that is exactly a Convoro database.
 */
class ConvoroImporter
{
    /**
     * The members who actually exist.
     *
     * 🚨 Convoro deletes a member by SETTING `deleted_at`, not by removing the
     * row, so a board that has cleared out spam registrations keeps every one
     * of them in `users`. Importing the lot turned a ten-member board into a
     * ninety-six-member one, and every one of those accounts arrived with a
     * working bcrypt password — a deleted account restored to a live login.
     *
     * Probed rather than assumed: the column is recent enough that an older
     * Convoro will not have it, and an unknown-column error on the members
     * phase takes the whole import with it.
     */
    private static function liveUsers(ConnectionInterface $conn): Builder
    {
        $query = $conn->table('users');

        try {
            // 🚨 Not SHOW COLUMNS: an uploaded dump runs as SQLite, which has no such thing.
            return $conn->getSchemaBuilder()->hasColumn('users', 'deleted_at') ? $query->whereNull('deleted_at') : $query;
        } catch (\Throwable) {
            return $query;
        }
    }

    /** The topics a guest or member could see there: not deleted, not held, not in a private social group. */
    private static function liveTopics(ConnectionInterface $conn, array $at): Builder
    {
        $q = $conn->table('topics');
        if ($at['topicDeleted']) {
            $q->whereNull('deleted_at');
        }
        if ($at['topicHidden']) {
            $q->where($at['topicHidden'], 0);
        }
        /*
         * Convoro 1 social groups. A private group's topics are its members'
         * only, and Flarum has no such group to hold them, so they stay
         * behind — an untagged discussion would be public to everyone. A
         * public group's topics could be read by anybody, so they come along.
         */
        if ($at['socialGroups']) {
            $q->where(fn ($w) => $w->whereNull('group_id')
                ->orWhereNotIn('group_id', fn ($s) => $s->from('social_groups')->where('is_private', 1)->select('id')));
        }

        return $q;
    }

    /** The same rule for a post on its own (a post's topic is checked by its mapping). */
    private static function livePosts(Builder $q, array $at): Builder
    {
        if ($at['postDeleted']) {
            $q->whereNull('deleted_at');
        }
        if ($at['postHidden']) {
            $q->where($at['postHidden'], 0);
        }

        return $q;
    }

    /**
     * Convoro 2 forums neither a guest nor a member could read: a forum is
     * restricted to the groups in `forum_groups`, or else inherits its
     * nearest restricted ancestor's (ForumVisibility::effectiveGroups). It is
     * open while one of those groups is the default (Members) or Guests group.
     * Both tables are small: read once per batch, never per forum.
     *
     * @return array<int, bool> forum id => restricted
     */
    private static function restrictedForums(ConnectionInterface $conn, array $at): array
    {
        if (! $at['forumGroups']) {
            return [];
        }
        $own = [];
        foreach ($conn->table('forum_groups')->leftJoin('groups', 'groups.id', '=', 'forum_groups.group_id')
            ->get(['forum_groups.forum_id', 'groups.is_default', 'groups.is_guest']) as $r) {
            $own[(int) $r->forum_id][] = (int) $r->is_default || (int) $r->is_guest;
        }
        if (! $own) {
            return [];
        }
        $parent = $conn->table('forums')->pluck('parent_id', 'id')->all();
        $out = [];
        foreach (array_keys($parent) as $id) {
            $seen = [];
            for ($f = (int) $id; $f > 0 && ! isset($seen[$f]); $f = (int) ($parent[$f] ?? 0)) {
                $seen[$f] = true;
                if (isset($own[$f])) {
                    $out[(int) $id] = ! in_array(true, $own[$f], true);

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Which of the two schemas this connection is, as a column map.
     *
     * @return array<string, mixed> forums, forumTitle, topicForum, postHtml, userName, then what is probed: see the comments
     */
    private static function layout(ConnectionInterface $conn): array
    {
        $sb = $conn->getSchemaBuilder();

        /*
         * 🚨 The member's display name is `username` in Convoro 2 and `name` in
         * 1.x, and this is checked on the COLUMN rather than inferred from the
         * schema version — because getting it wrong is silent. `$u->name` on a
         * Convoro 2 row is simply null, the fallback names everybody `user7`,
         * and an import that looks like it worked has lost every member's
         * identity. Seen on a real FBSFB database.
         */
        $userName = $sb->hasColumn('users', 'username') ? 'username' : 'name';
        $v2 = $sb->hasTable('forums');
        $forums = $v2 ? 'forums' : 'categories';

        /*
         * What the old board hid, probed per column (older installs predate
         * most of these). 🚨 Without them a deleted or held topic arrived as a
         * live discussion: Convoro deletes by setting `deleted_at`, and holds a
         * post for approval or a topic until its scheduled time with
         * `is_hidden` (2.x) / `hidden` (1.x).
         */
        $shared = [
            'userName' => $userName,
            'topicDeleted' => $sb->hasColumn('topics', 'deleted_at'),
            'topicHidden' => $sb->hasColumn('topics', 'is_hidden') ? 'is_hidden' : ($sb->hasColumn('topics', 'hidden') ? 'hidden' : null),
            'postDeleted' => $sb->hasColumn('posts', 'deleted_at'),
            'postHidden' => $sb->hasColumn('posts', 'is_hidden') ? 'is_hidden' : ($sb->hasColumn('posts', 'hidden') ? 'hidden' : null),
            'forumSlug' => $sb->hasColumn($forums, 'slug'),
            // Convoro 2: a forum with rows here is readable by those groups only.
            'forumGroups' => $v2 && $sb->hasTable('forum_groups') && $sb->hasTable('groups'),
            // Convoro 1: a topic posted inside a social group (`topics.group_id`).
            'socialGroups' => ! $v2 && $sb->hasColumn('topics', 'group_id') && $sb->hasTable('social_groups'),
            'banned' => $sb->hasColumn('users', 'is_banned') ? 'is_banned' : ($sb->hasColumn('users', 'banned_at') ? 'banned_at' : null),
            'banEnds' => $sb->hasColumn('users', 'ban_expires_at'),
            /*
             * 🚨 Convoro 2 only. Convoro 1 had the Laravel column but never
             * asked anybody to verify (MustVerifyEmail is commented out), so
             * it is null for nearly every member there and means nothing.
             */
            'verified' => $v2 && $sb->hasColumn('users', 'email_verified_at'),
        ];

        // Convoro 2: forums/title/forum_id/content_html.
        if ($sb->hasTable('forums')) {
            return [
                'forums' => 'forums',
                'forumTitle' => 'title',
                'topicForum' => 'forum_id',
                'postHtml' => $sb->hasColumn('posts', 'content_html') ? 'content_html' : 'body_html',
            ] + $shared;
        }

        // Convoro 1.x.
        return [
            'forums' => 'categories',
            'forumTitle' => 'name',
            'topicForum' => 'category_id',
            'postHtml' => 'body_html',
        ] + $shared;
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        $at = self::layout($conn);

        foreach (['users', $at['forums'], 'topics', 'posts'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException(Src::t('not_source.convoro', ['table' => $req]));
            }
        }

        /*
         * Disambiguate from any other “users/topics/posts” schema by a column
         * only Convoro has. 🚨 Checked against the layout rather than against a
         * literal: `body_html` is Convoro 1.x and `content_html` is Convoro 2,
         * and demanding the 1.x name rejected every Convoro 2 site.
         */
        if (! $sb->hasColumn('posts', $at['postHtml']) || ! $sb->hasColumn('topics', 'is_pinned')) {
            throw new \RuntimeException(Src::t('not_source.convoro_columns'));
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('users')->count(),
            'categories' => (int) $conn->table($at['forums'])->count(),
            'topics' => (int) $conn->table('topics')->count(),
            'posts' => (int) $conn->table('posts')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $hasTags = Dst::hasTags();
        $at = self::layout(Src::connect($cfg));

        return array_merge([
            new Phase(
                'tags',
                'forums',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($at['forums'])->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags, $at) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($at['forums'])->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $restricted = $rows->isNotEmpty() ? self::restrictedForums($ctx->src(), $at) : [];
                    $map = $slugs = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->id;
                        $name = (string) ($c->{$at['forumTitle']} ?? '');
                        $tagId = Dst::tag($name ?: 'Forum', Src::tagSlug($name ?: 'forum', (int) $c->id), $c->description ?? null, $c->color ?? null, (int) ($c->position ?? 0), restricted: $restricted[(int) $c->id] ?? false);
                        $map[$c->id] = $tagId;
                        // The old address names the forum by its slug.
                        if ($at['forumSlug'] && (string) ($c->slug ?? '') !== '') {
                            $slugs[(string) $c->slug] = $tagId;
                        }
                        /*
                         * The forum's cover art, where the board has somewhere
                         * to put it. Silently skipped without tag-covers — a
                         * cover is decoration, and an import that aborts over
                         * one has its priorities backwards.
                         */
                        Dst::tagCover($tagId, $c->cover_path ?? null, $ctx->cfg['assets_base'] ?? '');
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
                fn () => (int) self::liveUsers(Src::connect($cfg))->count(),
                function ($cursor, $limit, Ctx $ctx) use ($at) {
                    $rows = self::liveUsers($ctx->src())->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->id;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        try {
                            $id = Dst::user(
                                Src::username($u->{$at['userName']} ?? null, (int) $u->id), $email, $u->password ?? null, Src::ts($u->created_at ?? null),
                                confirmed: ! $at['verified'] || ! empty($u->email_verified_at),
                                suspendedUntil: self::bannedUntil($u, $at),
                                display: $u->{$at['userName']} ?? null,
                            );
                            $map[$u->id] = $id;
                            // Best effort; a member whose picture has gone is
                            // a member with no picture, not a failed import.
                            Dst::avatar($id, $u->avatar ?? null, $ctx->cfg['assets_base'] ?? '');
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
                fn () => (int) self::liveTopics(Src::connect($cfg), $at)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags, $at) {
                    $rows = self::liveTopics($ctx->src(), $at)->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck($at['topicForum'])->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('id'));
                    foreach ($rows as $t) {
                        $cursor = $t->id;
                        if (isset($known[(string) $t->id])) {
                            continue;
                        }
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->created_at ?? null), (bool) ($t->is_pinned ?? false), (bool) ($t->is_locked ?? false));
                        $map[$t->id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->{$at['topicForum']}])) {
                            Dst::attachTag($did, $tagMap[(string) $t->{$at['topicForum']}]);
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
                fn () => (int) self::livePosts(Src::connect($cfg)->table('posts'), $at)->count(),
                fn ($post) => [
                    'tid' => (int) $post->topic_id, 'pid' => (int) $post->id, 'uid' => $post->user_id,
                    'html' => (string) ($post->{$at['postHtml']} ?? ''), 'at' => Src::ts($post->created_at ?? null), 'ok' => true,
                ],
                fn (Builder $q) => self::livePosts($q, $at),
                /*
                 * 🚨 No `edited_at` here, though Convoro has one: Phases::edits
                 * compares the column with unix seconds, and Convoro's is a
                 * datetime, which then reads as "edited since" on every sync.
                 */
                null,
                self::attachments(),
            ),
        ], Phases::tail());
    }

    /**
     * Groups: `groups` (`is_admin`, `is_moderator`, `is_guest` and
     * `is_default`, the Members group, say which are Convoro's own) and
     * `group_members`. Convoro keeps no birthdays, and its profile photos
     * come across with the members.
     */
    public static function groups(array $cfg): array
    {
        return [
            'needs' => ['groups', 'group_members'],
            'groups' => function (Ctx $ctx) {
                $conn = $ctx->src();

                return $conn->table('groups')->orderBy('id')->get()->map(fn ($g) => [
                    'id' => (string) $g->id, 'name' => (string) $g->name, 'color' => $g->color ?? null,
                    'role' => match (true) {
                        ! empty($g->is_admin) => 'admin', ! empty($g->is_moderator) => 'mod',
                        ! empty($g->is_guest) => 'guest', ! empty($g->is_default) => 'member', default => null,
                    },
                ])->all();
            },
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::pivot(self::liveUsers($ctx->src()), 'id', $after, $limit,
                fn (array $ids) => $ctx->src()->table('group_members')->whereIn('user_id', $ids)->get()->map(fn ($r) => [$r->user_id, $r->group_id])),
        ];
    }

    /**
     * Attachments: `attachments` of the post (`owner_type` post and
     * `owner_id`, or `post_id` before attachments could belong to anything),
     * the file at content/attachments/<path>, served at /attachment/<token>.
     */
    public static function attachments(): array
    {
        return ['fetch' => function (array $ids, Ctx $ctx) {
            $conn = $ctx->src();
            $sb = $conn->getSchemaBuilder();
            if (! $sb->hasTable('attachments')) {
                return [];
            }
            $owner = $sb->hasColumn('attachments', 'owner_type');
            $col = $owner ? 'owner_id' : 'post_id';
            $out = [];
            foreach ($conn->table('attachments')->whereIn($col, $ids)->when($owner, fn ($q) => $q->where('owner_type', 'post'))->orderBy('id')->get() as $a) {
                $out[$a->$col][] = [
                    'id' => (string) $a->id, 'name' => (string) $a->original_name, 'uid' => $a->user_id ?? null,
                    'root' => 'content/attachments', 'path' => (string) $a->path, 'url' => 'attachment/'.$a->token,
                    'keys' => ['attachment/'.$a->token],
                ];
            }

            return $out;
        }, 'inline' => '#/attachment/#'];
    }
    /**
     * Banned there, suspended here: Convoro 2 `is_banned` until
     * `ban_expires_at` (null = for good), Convoro 1 `banned_at` (always for good).
     */
    private static function bannedUntil(object $u, array $at): ?Carbon
    {
        if (! $at['banned'] || empty($u->{$at['banned']})) {
            return null;
        }

        return $at['banEnds'] && ! empty($u->ban_expires_at) ? Src::ts($u->ban_expires_at) : Carbon::create(2038, 1, 1);
    }

    /**
     * The messenger. Convoro 2: `conversations` (`subject`),
     * `conversation_participants` (`has_left`, `last_read_at`) and `messages`
     * (`content_html`). Convoro 1.x: `conversations` (`title`),
     * `conversation_user` (`last_read_at`) and `messages` (`body_html`).
     */
    public static function privateMessages(array $cfg): array
    {
        $layout = function ($conn) {
            $sb = $conn->getSchemaBuilder();
            if (! $sb->hasTable('messages') || ! $sb->hasTable('conversations')) {
                return null;
            }
            $who = $sb->hasTable('conversation_participants') ? 'conversation_participants' : ($sb->hasTable('conversation_user') ? 'conversation_user' : null);

            return $who ? [
                'who' => $who,
                'left' => $sb->hasColumn($who, 'has_left'),
                'html' => $sb->hasColumn('messages', 'content_html') ? 'content_html' : 'body_html',
                'title' => $sb->hasColumn('conversations', 'subject') ? 'subject' : 'title',
            ] : null;
        };

        return [
            'count' => function () use ($cfg, $layout) {
                $conn = Src::connect($cfg);

                return $layout($conn) ? (int) $conn->table('messages')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($layout) {
                $conn = $ctx->src();
                if (! $at = $layout($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table('messages')->where('id', '>', $after)->orderBy('id')->limit($limit)->get();
                $ids = $rows->pluck('conversation_id')->unique()->values()->all();
                $titles = $ids ? $conn->table('conversations')->whereIn('id', $ids)->pluck($at['title'], 'id')->all() : [];
                $who = [];
                foreach ($ids ? $conn->table($at['who'])->whereIn('conversation_id', $ids)->get() : [] as $r) {
                    $who[$r->conversation_id][] = $r;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->id;
                    $at2 = Src::ts($m->created_at ?? null);
                    $rs = $who[$m->conversation_id] ?? [];
                    $in = array_filter($rs, fn ($r) => ! $at['left'] || ! (int) $r->has_left);
                    $out[] = [
                        'id' => (string) $m->id,
                        'conv' => (string) $m->conversation_id,
                        'thread' => (string) $m->conversation_id,
                        'title' => (string) ($titles[$m->conversation_id] ?? ''),
                        'uid' => $m->user_id,
                        'people' => array_map(fn ($r) => $r->user_id, $rs),
                        'keep' => array_map(fn ($r) => $r->user_id, $in),
                        'unread' => array_map(fn ($r) => $r->user_id, array_filter($in, fn ($r) => empty($r->last_read_at) || Src::ts($r->last_read_at) < $at2)),
                        'html' => (string) ($m->{$at['html']} ?? ''),
                        'at' => $at2,
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * A topic's poll. Convoro 2 has `label`, `is_multiple` + `max_choices`
     * (0 = any number), `is_public`, `hide_results` and `closed_at` for a poll
     * closed early; Convoro 1 has `text` and `max_choices` (1 = one choice).
     * A vote can be changed on either.
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
                $label = $conn->getSchemaBuilder()->hasColumn('poll_options', 'label') ? 'label' : 'text';
                [$rows, $cursor, $done, $n] = Polls::page($conn->table('polls'), 'id', $after, $limit, $ctx);
                $ids = $rows->pluck('id')->all();
                $authors = $ids ? $conn->table('topics')->whereIn('id', $rows->pluck('topic_id')->all())->pluck('user_id', 'id')->all() : [];
                $options = $votes = [];
                foreach ($ids ? $conn->table('poll_options')->whereIn('poll_id', $ids)->orderBy('position')->orderBy('id')->get(['id', 'poll_id', $label]) : [] as $o) {
                    $options[$o->poll_id][(string) $o->id] = ['text' => (string) $o->$label, 'count' => null];
                }
                foreach ($ids ? $conn->table('poll_votes')->whereIn('poll_id', $ids)->get(['poll_id', 'option_id', 'user_id', 'created_at']) : [] as $v) {
                    $votes[$v->poll_id][] = [$v->user_id, (string) $v->option_id, Polls::at($v->created_at)];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $out[] = [
                        'id' => (string) $poll->id,
                        'topic' => (string) $poll->topic_id,
                        'question' => (string) $poll->question,
                        'uid' => $authors[$poll->topic_id] ?? null,
                        'at' => Src::ts($poll->created_at ?? null),
                        'ends' => Polls::at($poll->closed_at ?? null) ?? Polls::at($poll->closes_at ?? null),
                        'max' => property_exists($poll, 'is_multiple') ? ((int) $poll->is_multiple ? (int) $poll->max_choices : 1) : (int) ($poll->max_choices ?? 1),
                        'public' => (bool) ($poll->is_public ?? false),
                        'change' => true,
                        'hide' => (bool) ($poll->hide_results ?? false),
                        'options' => $options[$poll->id] ?? [],
                        'votes' => $votes[$poll->id] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
