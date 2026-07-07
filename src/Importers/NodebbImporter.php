<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Carbon;

/**
 * NodeBB (Redis-backed installs) → Flarum.
 *
 * NodeBB stores everything as Redis hashes + sorted sets, so this reads the
 * key/value store directly (raw phpredis, so no Flarum key prefix is prepended):
 *   categories:cid + category:{cid}   → tags
 *   users:joindate + user:{uid}       → users
 *   topics:tid + topic:{tid}          → discussions (topic.mainPid = post #1)
 *   posts:pid + post:{pid}            → replies
 * Content is Markdown; timestamps are epoch-milliseconds; bcrypt hashes are
 * sha512-wrapped (not portable) so members reset. Batches walk each sorted set
 * by index, so it stays timeout-proof like the SQL importers.
 *
 * cfg: host, port (6379), database (Redis DB index, 0), password.
 */
class NodebbImporter
{
    private static function redis(array $cfg): \Redis
    {
        if (! class_exists(\Redis::class)) {
            throw new \RuntimeException('Importing from NodeBB requires the PHP “redis” (phpredis) extension on the server.');
        }
        $host = ($cfg['host'] ?? '') ?: '127.0.0.1';
        $port = (int) (($cfg['port'] ?? 0) ?: 6379);
        $r = new \Redis;
        if (! @$r->connect($host, $port, 3.0)) {
            throw new \RuntimeException("Could not connect to the NodeBB Redis server at {$host}:{$port}.");
        }
        if (($cfg['password'] ?? '') !== '') {
            $r->auth($cfg['password']);
        }
        $r->select((int) ($cfg['database'] ?? 0));

        return $r;
    }

    private static function hash(\Redis $r, string $key): array
    {
        $h = $r->hGetAll($key);

        return is_array($h) ? $h : [];
    }

    /** NodeBB timestamps are epoch milliseconds. */
    private static function ts($ms): Carbon
    {
        return ($ms === null || $ms === '') ? Carbon::now() : Src::ts((int) ((int) $ms / 1000));
    }

    public static function test(array $cfg): array
    {
        $r = self::redis($cfg);
        if (! $r->exists('global') && ! $r->exists('categories:cid')) {
            throw new \RuntimeException("This doesn't look like a NodeBB Redis store (no “global” or “categories:cid” key). Check the host, port and database number.");
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $r->zCard('users:joindate'),
            'categories' => (int) $r->zCard('categories:cid'),
            'topics' => (int) $r->zCard('topics:tid'),
            'posts' => (int) $r->zCard('posts:pid'),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => $hasTags ? (int) self::redis($cfg)->zCard('categories:cid') : 0,
                function ($cursor, $limit, Ctx $ctx) use ($cfg, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $r = self::redis($cfg);
                    $off = (int) $cursor;
                    $cids = $r->zRange('categories:cid', $off, $off + $limit - 1);
                    $map = [];
                    $n = 0;
                    foreach ($cids as $cid) {
                        $c = self::hash($r, "category:{$cid}");
                        if (! $c || ($c['disabled'] ?? '0') === '1') {
                            continue;
                        }
                        $name = trim((string) ($c['name'] ?? '')) ?: ('Category ' . $cid);
                        $map[$cid] = Dst::tag($name, Src::tagSlug($name, (int) $cid), $c['description'] ?? null, $c['bgColor'] ?? null, (int) ($c['order'] ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => $off + count($cids), 'processed' => count($cids), 'done' => count($cids) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) self::redis($cfg)->zCard('users:joindate'),
                function ($cursor, $limit, Ctx $ctx) use ($cfg) {
                    $r = self::redis($cfg);
                    $off = (int) $cursor;
                    $uids = $r->zRange('users:joindate', $off, $off + $limit - 1);
                    $map = [];
                    $n = $skip = 0;
                    foreach ($uids as $uid) {
                        $u = self::hash($r, "user:{$uid}");
                        $email = trim((string) ($u['email'] ?? ''));
                        if (! $u || $email === '') {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$uid] = Dst::user(Src::username($u['username'] ?? null, (int) $uid), $email, null, self::ts($u['joindate'] ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => $off + count($uids), 'processed' => count($uids), 'done' => count($uids) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            // Topic + its first post (topic.mainPid). Reply-less topics still get
            // finalised here so their counts are right.
            new Phase('topics', 'Importing topics…',
                fn () => (int) self::redis($cfg)->zCard('topics:tid'),
                function ($cursor, $limit, Ctx $ctx) use ($cfg, $hasTags) {
                    $r = self::redis($cfg);
                    $off = (int) $cursor;
                    $tids = $r->zRange('topics:tid', $off, $off + $limit - 1);
                    $hashes = $uids = $cids = [];
                    foreach ($tids as $tid) {
                        $t = self::hash($r, "topic:{$tid}");
                        $hashes[$tid] = $t;
                        if ($t) {
                            $uids[] = $t['uid'] ?? null;
                            $cids[] = $t['cid'] ?? null;
                        }
                    }
                    $userMap = $ctx->mapGet('user', $uids);
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $cids) : [];
                    $topicMap = $mainMap = [];
                    $n = 0;
                    foreach ($tids as $tid) {
                        $t = $hashes[$tid];
                        if (! $t || ($t['deleted'] ?? '0') === '1') {
                            continue;
                        }
                        $title = trim((string) ($t['title'] ?? '')) ?: 'Untitled';
                        $created = self::ts($t['timestamp'] ?? null);
                        $uid = $userMap[(string) ($t['uid'] ?? '')] ?? null;
                        $did = Dst::discussion($title, $uid, $created, ($t['pinned'] ?? '0') === '1', ($t['locked'] ?? '0') === '1');
                        $topicMap[$tid] = $did;
                        if ($hasTags && isset($tagMap[(string) ($t['cid'] ?? '')])) {
                            Dst::attachTag($did, $tagMap[(string) $t['cid']]);
                        }
                        $mainPid = $t['mainPid'] ?? null;
                        if ($mainPid !== null && $mainPid !== '') {
                            $post = self::hash($r, "post:{$mainPid}");
                            Dst::post($did, 1, $userMap[(string) ($post['uid'] ?? '')] ?? $uid, Src::markdown($post['content'] ?? '') ?: '<p></p>', self::ts($post['timestamp'] ?? ($t['timestamp'] ?? null)));
                            $mainMap[$mainPid] = $did;
                        } else {
                            Dst::post($did, 1, $uid, '<p></p>', $created);
                        }
                        Dst::finalizeDiscussion($did);
                        $n++;
                    }
                    $ctx->mapPut('topic', $topicMap);
                    $ctx->mapPut('main', $mainMap);

                    return ['cursor' => $off + count($tids), 'processed' => count($tids), 'done' => count($tids) < $limit, 'summary' => ['topics' => $n, 'posts' => $n]];
                }
            ),

            // Replies: every post except the ones already imported as a topic's first post.
            new Phase('posts', 'Importing posts…',
                fn () => (int) self::redis($cfg)->zCard('posts:pid'),
                function ($cursor, $limit, Ctx $ctx) use ($cfg) {
                    $r = self::redis($cfg);
                    $off = (int) $cursor;
                    $pids = $r->zRange('posts:pid', $off, $off + $limit - 1);
                    $hashes = $tids = $uids = [];
                    foreach ($pids as $pid) {
                        $post = self::hash($r, "post:{$pid}");
                        $hashes[$pid] = $post;
                        if ($post) {
                            $tids[] = $post['tid'] ?? null;
                            $uids[] = $post['uid'] ?? null;
                        }
                    }
                    $topicMap = $ctx->mapGet('topic', $tids);
                    $userMap = $ctx->mapGet('user', $uids);
                    $mainMap = $ctx->mapGet('main', $pids);
                    $db = Dst::db();
                    $counts = $touched = [];
                    $n = 0;
                    foreach ($pids as $pid) {
                        $post = $hashes[$pid];
                        if (! $post || ($post['deleted'] ?? '0') === '1' || isset($mainMap[(string) $pid])) {
                            continue; // deleted, or already imported as a topic's first post
                        }
                        $did = $topicMap[(string) ($post['tid'] ?? '')] ?? null;
                        if (! $did) {
                            continue;
                        }
                        if (! isset($counts[$did])) {
                            $counts[$did] = (int) ($db->table('posts')->where('discussion_id', $did)->max('number') ?? 0);
                        }
                        try {
                            Dst::post($did, ++$counts[$did], $userMap[(string) ($post['uid'] ?? '')] ?? null, Src::markdown($post['content'] ?? '') ?: '<p></p>', self::ts($post['timestamp'] ?? null));
                            $n++;
                            $touched[$did] = true;
                        } catch (\Throwable) {
                            $counts[$did]--;
                        }
                    }
                    foreach (array_keys($touched) as $did) {
                        Dst::finalizeDiscussion((int) $did);
                    }

                    return ['cursor' => $off + count($pids), 'processed' => count($pids), 'done' => count($pids) < $limit, 'summary' => ['posts' => $n]];
                }
            ),
        ], Phases::tail());
    }
}
