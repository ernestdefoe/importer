<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * phpBB 3.x → Flarum.
 *   phpbb_forums → tags · phpbb_users → users · phpbb_topics → discussions · phpbb_posts → posts
 */
class PhpbbImporter
{
    private static function prefix(array $cfg): string
    {
        return trim((string) ($cfg['prefix'] ?? 'phpbb_')) ?: 'phpbb_';
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'forums', 'topics', 'posts'] as $req) {
            if (! $sb->hasTable($p . $req)) {
                throw new \RuntimeException("This doesn't look like a phpBB database (missing “{$p}{$req}”). Check the table prefix.");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p . 'users')->count(),
            'categories' => (int) $conn->table($p . 'forums')->count(),
            'topics' => (int) $conn->table($p . 'topics')->count(),
            'posts' => (int) $conn->table($p . 'posts')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = self::prefix($cfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p . 'forums')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $conn = $ctx->src();
                    $hasType = $conn->getSchemaBuilder()->hasColumn($p . 'forums', 'forum_type');
                    $rows = $conn->table($p . 'forums')->where('forum_id', '>', (int) $cursor)->orderBy('forum_id')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->forum_id;
                        if ($hasType && (int) ($f->forum_type ?? 1) !== 1) {
                            continue;
                        }
                        $map[$f->forum_id] = Dst::tag($f->forum_name ?: 'Forum', Src::tagSlug($f->forum_name ?: 'forum', (int) $f->forum_id), $f->forum_desc ?? null, null, (int) ($f->left_id ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table($p . 'users')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $rows = $ctx->src()->table($p . 'users')->where('user_id', '>', (int) $cursor)->orderBy('user_id')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->user_id;
                        $email = trim((string) ($u->user_email ?? ''));
                        if ($email === '' || (int) ($u->user_type ?? 0) === 2) {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->user_id] = Dst::user(Src::username($u->username ?? null, (int) $u->user_id), $email, $u->user_password ?? null, Src::ts($u->user_regdate ?? null));
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
                fn () => (int) Src::connect($cfg)->table($p . 'topics')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $conn = $ctx->src();
                    $visCol = $conn->getSchemaBuilder()->hasColumn($p . 'topics', 'topic_visibility') ? 'topic_visibility' : null;
                    $rows = $conn->table($p . 'topics')->where('topic_id', '>', (int) $cursor)->orderBy('topic_id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('topic_poster')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('forum_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->topic_id;
                        if ($visCol && (int) ($t->$visCol ?? 1) !== 1) {
                            continue;
                        }
                        $did = Dst::discussion($t->topic_title ?: 'Untitled', $userMap[(string) $t->topic_poster] ?? null, Src::ts($t->topic_time ?? null), (int) ($t->topic_type ?? 0) >= 1);
                        $map[$t->topic_id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->forum_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->forum_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table($p . 'posts')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $sb = $ctx->src()->getSchemaBuilder();
                    $visCol = $sb->hasColumn($p . 'posts', 'post_visibility') ? 'post_visibility'
                        : ($sb->hasColumn($p . 'posts', 'post_approved') ? 'post_approved' : null);

                    return Phases::postsBatch($cursor, $limit, $ctx,
                        fn ($conn, $cur, $lim) => $conn->table($p . 'posts')
                            ->where(fn ($q) => $q->where('topic_id', '>', (int) $cur['tid'])
                                ->orWhere(fn ($q2) => $q2->where('topic_id', (int) $cur['tid'])->where('post_id', '>', (int) $cur['pid'])))
                            ->orderBy('topic_id')->orderBy('post_id')->limit($lim)->get(),
                        fn ($post) => [
                            'tid' => (int) $post->topic_id, 'pid' => (int) $post->post_id, 'uid' => $post->poster_id,
                            'html' => Bbcode::toHtml($post->post_text ?? '', ['uid' => (string) ($post->bbcode_uid ?? ''), 'escaped' => true]),
                            'at' => Src::ts($post->post_time ?? null),
                            'ok' => ! ($visCol && (int) ($post->$visCol ?? 1) !== 1),
                        ]
                    );
                }
            ),
        ], Phases::tail());
    }
}
