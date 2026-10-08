<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

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
     * Which of the two schemas this connection is, as a column map.
     *
     * @return array{forums: string, forumTitle: string, topicForum: string, postHtml: string, userName: string}
     */
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
            $table = method_exists($conn, 'getTablePrefix') ? $conn->getTablePrefix().'users' : 'users';

            foreach ($conn->select("SHOW COLUMNS FROM `{$table}`") as $col) {
                $field = is_array($col) ? ($col['Field'] ?? null) : ($col->Field ?? null);

                if ($field === 'deleted_at') {
                    return $query->whereNull('deleted_at');
                }
            }
        } catch (\Throwable) {
            // Older schema, or a driver that does not answer SHOW COLUMNS.
        }

        return $query;
    }

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

        // Convoro 2: forums/title/forum_id/content_html.
        if ($sb->hasTable('forums')) {
            return [
                'forums' => 'forums',
                'forumTitle' => 'title',
                'topicForum' => 'forum_id',
                'postHtml' => $sb->hasColumn('posts', 'content_html') ? 'content_html' : 'body_html',
                'userName' => $userName,
            ];
        }

        // Convoro 1.x.
        return [
            'forums' => 'categories',
            'forumTitle' => 'name',
            'topicForum' => 'category_id',
            'postHtml' => 'body_html',
            'userName' => $userName,
        ];
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        $at = self::layout($conn);

        foreach (['users', $at['forums'], 'topics', 'posts'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException("This doesn't look like a Convoro database (missing “{$req}”).");
            }
        }

        /*
         * Disambiguate from any other “users/topics/posts” schema by a column
         * only Convoro has. 🚨 Checked against the layout rather than against a
         * literal: `body_html` is Convoro 1.x and `content_html` is Convoro 2,
         * and demanding the 1.x name rejected every Convoro 2 site.
         */
        if (! $sb->hasColumn('posts', $at['postHtml']) || ! $sb->hasColumn('topics', 'is_pinned')) {
            throw new \RuntimeException("This database has the right table names but not Convoro's columns — is it really a Convoro forum?");
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
            new Phase('tags', 'Importing forums…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($at['forums'])->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags, $at) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($at['forums'])->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->id;
                        $name = (string) ($c->{$at['forumTitle']} ?? '');
                        $tagId = Dst::tag($name ?: 'Forum', Src::tagSlug($name ?: 'forum', (int) $c->id), $c->description ?? null, $c->color ?? null, (int) ($c->position ?? 0));
                        $map[$c->id] = $tagId;
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

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
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
                            $id = Dst::user(Src::username($u->{$at['userName']} ?? null, (int) $u->id), $email, $u->password ?? null, Src::ts($u->created_at ?? null));
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

            new Phase('topics', 'Importing topics…',
                fn () => (int) Src::connect($cfg)->table('topics')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags, $at) {
                    $rows = $ctx->src()->table('topics')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck($at['topicForum'])->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->id;
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

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table('posts')->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table('posts')
                        ->where(fn ($q) => $q->where('topic_id', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('topic_id', (int) $cur['tid'])->where('id', '>', (int) $cur['pid'])))
                        ->orderBy('topic_id')->orderBy('id')->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->topic_id, 'pid' => (int) $post->id, 'uid' => $post->user_id,
                        'html' => (string) ($post->{$at['postHtml']} ?? ''), 'at' => Src::ts($post->created_at ?? null), 'ok' => true,
                    ]
                )
            ),
        ], Phases::tail());
    }
}
