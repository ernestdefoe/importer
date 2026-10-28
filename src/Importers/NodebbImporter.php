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
            throw new \RuntimeException(Src::t('nodebb.no_extension'));
        }
        $host = ($cfg['host'] ?? '') ?: '127.0.0.1';
        $port = (int) (($cfg['port'] ?? 0) ?: 6379);
        $r = new \Redis;
        if (! @$r->connect($host, $port, 3.0)) {
            throw new \RuntimeException(Src::t('nodebb.no_connect', ['host' => $host, 'port' => $port]));
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
    private static function ts(mixed $ms): Carbon
    {
        return ($ms === null || $ms === '') ? Carbon::now() : Src::ts((int) ((int) $ms / 1000));
    }

    public static function test(array $cfg): array
    {
        $r = self::redis($cfg);
        if (! $r->exists('global') && ! $r->exists('categories:cid')) {
            throw new \RuntimeException(Src::t('not_source.nodebb'));
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
            new Phase(
                'tags',
                'categories',
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
                        $name = trim((string) ($c['name'] ?? '')) ?: ('Category '.$cid);
                        $map[$cid] = Dst::tag($name, Src::tagSlug($name, (int) $cid), $c['description'] ?? null, $c['bgColor'] ?? null, (int) ($c['order'] ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => $off + count($cids), 'processed' => count($cids), 'done' => count($cids) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
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
                            // bcrypt of the password, or of its sha512 where NodeBB "shaWrapped" it.
                            $hash = (string) ($u['password'] ?? '');
                            $wrapped = (int) ($u['password:shaWrapped'] ?? 0) === 1;
                            $map[$uid] = Dst::user(Src::username($u['username'] ?? null, (int) $uid), $email, $wrapped ? null : $hash, self::ts($u['joindate'] ?? null), null, $wrapped && $hash !== '' ? ['scheme' => 'nodebb', 'hash' => $hash, 'salt' => null] : null, display: $u['username'] ?? null);
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
            new Phase(
                'topics',
                'topics',
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
                    $mains = $bodies = [];
                    foreach ($tids as $tid) {
                        $mainPid = $hashes[$tid]['mainPid'] ?? null;
                        if ($hashes[$tid] && $mainPid !== null && $mainPid !== '') {
                            $mains[$tid] = self::hash($r, "post:{$mainPid}");
                            $uids[] = $mains[$tid]['uid'] ?? null;
                            $bodies[$tid] = Src::markdown($mains[$tid]['content'] ?? '', ['mentions' => true]) ?: '<p></p>';
                        }
                    }
                    $userMap = $ctx->mapGet('user', $uids);
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $cids) : [];
                    Dst::prepare($ctx, $bodies);
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
                        if (isset($mains[$tid])) {
                            $post = $mains[$tid];
                            Dst::post($did, 1, $userMap[(string) ($post['uid'] ?? '')] ?? $uid, $bodies[$tid], self::ts($post['timestamp'] ?? ($t['timestamp'] ?? null)));
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
            new Phase(
                'posts',
                'posts',
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
                    $bodies = [];
                    foreach ($pids as $pid) {
                        if ($hashes[$pid] && ! isset($mainMap[(string) $pid])) {
                            $bodies[$pid] = Src::markdown($hashes[$pid]['content'] ?? '', ['mentions' => true]) ?: '<p></p>';
                        }
                    }
                    Dst::prepare($ctx, $bodies);
                    $touched = [];
                    $n = 0;
                    // NodeBB orders posts by pid, not by topic, so one batch can span
                    // dozens of discussions. Fetch every starting post number in a
                    // single grouped query instead of one MAX() per discussion.
                    $counts = self::maxPostNumbers(array_values(array_unique(array_filter(
                        array_map(fn ($pid) => $topicMap[(string) ($hashes[$pid]['tid'] ?? '')] ?? null, $pids)
                    ))));
                    foreach ($pids as $pid) {
                        $post = $hashes[$pid];
                        if (! $post || ($post['deleted'] ?? '0') === '1' || isset($mainMap[(string) $pid])) {
                            continue; // deleted, or already imported as a topic's first post
                        }
                        $did = $topicMap[(string) ($post['tid'] ?? '')] ?? null;
                        if (! $did) {
                            continue;
                        }
                        $counts[$did] ??= 0;

                        try {
                            Dst::post($did, ++$counts[$did], $userMap[(string) ($post['uid'] ?? '')] ?? null, $bodies[$pid], self::ts($post['timestamp'] ?? null));
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

    /**
     * Highest existing post `number` for each of the given discussions, in one
     * grouped query. Discussions with no posts yet are simply absent from the
     * result (callers default them to 0).
     *
     * @param  array<int>  $discussionIds
     * @return array<int,int>
     */
    /**
     * Chats. Each message is the hash `message:<mid>` (`fromuid`, `content`
     * in Markdown, `timestamp` in ms, `roomId`, `deleted`, `system`), mids
     * counted up by `global.nextMid`; a room is `chat:room:<id>` (`roomName`,
     * `public` for the open chat channels of NodeBB 3, which are not private)
     * with its members in the sorted set `chat:room:<id>:uids` — leaving a room
     * takes you off it. How far a member has read is `uid:<uid>:chat:rooms:read`
     * (room => ms). Messages from before rooms (NodeBB 0.x) name a `touid`.
     */
    public static function privateMessages(array $cfg): array
    {
        return [
            'count' => fn () => (int) (self::redis($cfg)->hGet('global', 'nextMid') ?: 0),
            'fetch' => function (int $after, int $limit) use ($cfg) {
                $r = self::redis($cfg);
                $last = (int) ($r->hGet('global', 'nextMid') ?: 0);
                $upto = min($last, $after + $limit);
                $rooms = $members = $read = [];
                $out = [];
                for ($mid = $after + 1; $mid <= $upto; $mid++) {
                    $m = self::hash($r, "message:{$mid}");
                    if (! $m || ($m['deleted'] ?? '0') === '1' || ! empty($m['system'])) {
                        continue;
                    }
                    $room = (string) ($m['roomId'] ?? '');
                    if ($room === '') {
                        $people = $keep = [$m['fromuid'] ?? '', $m['touid'] ?? ''];
                        $title = '';
                    } else {
                        $rooms[$room] ??= self::hash($r, "chat:room:{$room}");
                        if (! $rooms[$room] || ($rooms[$room]['public'] ?? '0') === '1') {
                            continue;
                        }
                        $members[$room] ??= array_map('strval', $r->zRange("chat:room:{$room}:uids", 0, -1) ?: []);
                        $people = array_merge([$m['fromuid'] ?? ''], $members[$room]);
                        $keep = $members[$room];
                        $title = (string) ($rooms[$room]['roomName'] ?? '');
                    }
                    $at = (int) ($m['timestamp'] ?? 0);
                    $unread = [];
                    if ($room !== '') {
                        foreach ($keep as $u) {
                            $read[$u][$room] ??= (int) ($r->hGet("uid:{$u}:chat:rooms:read", $room) ?: 0);
                            if ($read[$u][$room] > 0 && $read[$u][$room] < $at) {
                                $unread[] = $u;
                            }
                        }
                    }
                    $out[] = [
                        'id' => (string) $mid,
                        'conv' => $room !== '' ? $room : PrivateMessages::conv('', $people),
                        'thread' => $room,
                        'title' => $title,
                        'uid' => $m['fromuid'] ?? null,
                        'people' => $people,
                        'keep' => $keep,
                        'unread' => $unread,
                        'html' => Src::markdown($m['content'] ?? '', ['mentions' => true]),
                        'at' => self::ts($m['timestamp'] ?? null),
                    ];
                }

                return ['cursor' => $upto, 'rows' => max(0, $upto - $after), 'done' => $upto >= $last, 'messages' => $out];
            },
        ];
    }

    private static function maxPostNumbers(array $discussionIds): array
    {
        if (! $discussionIds) {
            return [];
        }

        return Dst::db()->table('posts')
            ->whereIn('discussion_id', $discussionIds)
            ->groupBy('discussion_id')
            ->selectRaw('discussion_id, MAX(number) AS max_number')
            ->pluck('max_number', 'discussion_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
