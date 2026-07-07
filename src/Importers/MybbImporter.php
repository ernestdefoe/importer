<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * MyBB 1.8.x → Flarum.
 *   mybb_forums (type 'f') → tags · mybb_users → users · mybb_threads → discussions · mybb_posts → posts
 * MyBB passwords are md5(md5(salt).md5(pw)) — not portable, so members reset.
 * Post bodies are MyCode (BBCode). Table prefix defaults to `mybb_`.
 */
class MybbImporter
{
    private static function prefix(array $cfg): string
    {
        return trim((string) ($cfg['prefix'] ?? 'mybb_')) ?: 'mybb_';
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'forums', 'threads', 'posts'] as $req) {
            if (! $sb->hasTable($p . $req)) {
                throw new \RuntimeException("This doesn't look like a MyBB database (missing “{$p}{$req}”). Check the table prefix.");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p . 'users')->count(),
            'categories' => (int) $conn->table($p . 'forums')->where('type', 'f')->count(),
            'topics' => (int) $conn->table($p . 'threads')->where('visible', 1)->count(),
            'posts' => (int) $conn->table($p . 'posts')->where('visible', 1)->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = self::prefix($cfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p . 'forums')->where('type', 'f')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p . 'forums')->where('type', 'f')->where('fid', '>', (int) $cursor)->orderBy('fid')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->fid;
                        if (trim((string) ($f->linkto ?? '')) !== '') {
                            continue; // redirect/link forum — no content
                        }
                        $map[$f->fid] = Dst::tag($f->name ?: 'Forum', Src::tagSlug($f->name ?: 'forum', (int) $f->fid), $f->description ?? null, null, (int) ($f->disporder ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table($p . 'users')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $rows = $ctx->src()->table($p . 'users')->where('uid', '>', (int) $cursor)->orderBy('uid')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->uid;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->uid] = Dst::user(Src::username($u->username ?? null, (int) $u->uid), $email, null, Src::ts($u->regdate ?? null));
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
                fn () => (int) Src::connect($cfg)->table($p . 'threads')->where('visible', 1)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $rows = $ctx->src()->table($p . 'threads')->where('visible', 1)->where('tid', '>', (int) $cursor)->orderBy('tid')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('uid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('fid')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->tid;
                        $did = Dst::discussion($t->subject ?: 'Untitled', $userMap[(string) $t->uid] ?? null, Src::ts($t->dateline ?? null), (bool) ($t->sticky ?? false));
                        $map[$t->tid] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->fid])) {
                            Dst::attachTag($did, $tagMap[(string) $t->fid]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table($p . 'posts')->where('visible', 1)->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table($p . 'posts')->where('visible', 1)
                        ->where(fn ($q) => $q->where('tid', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('tid', (int) $cur['tid'])->where('pid', '>', (int) $cur['pid'])))
                        ->orderBy('tid')->orderBy('pid')->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->tid, 'pid' => (int) $post->pid, 'uid' => $post->uid,
                        'html' => Bbcode::toHtml($post->message ?? ''), 'at' => Src::ts($post->dateline ?? null), 'ok' => true,
                    ]
                )
            ),
        ], Phases::tail());
    }
}
