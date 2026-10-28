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

    /**
     * Many hashes in ONE round trip: a pipeline, where a call per key costs a
     * network round trip per topic, post or member.
     *
     * @param  string[]  $keys
     * @return array<int|string, array<string, string>>  same keys as $keys, [] for a missing hash
     */
    private static function hashes(\Redis $r, array $keys): array
    {
        if (! $keys) {
            return [];
        }
        $pipe = $r->multi(\Redis::PIPELINE);
        foreach ($keys as $key) {
            $pipe->hGetAll($key);
        }
        $res = array_values($r->exec() ?: []);
        $out = [];
        foreach (array_keys($keys) as $i => $k) {
            $out[$k] = is_array($res[$i] ?? null) ? $res[$i] : [];
        }

        return $out;
    }

    /** "{prefix}{id}" for each id, keyed by the id. */
    private static function keys(string $prefix, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[(string) $id] = $prefix.$id;
        }

        return $out;
    }

    /**
     * Uploads are addresses in the posts (/assets/uploads/files/…), kept in
     * the NodeBB folder's public/uploads/: copied where each post shows them.
     */
    public static function attachments(): array
    {
        return ['inline' => '#/assets/uploads/#', 'disk' => ['#^.*?/assets/uploads/#', 'public/uploads']];
    }

    /**
     * Groups: every name in `groups:createtime`, its `group:<name>` hash and
     * `group:<name>:members` set. administrators and Global Moderators are
     * NodeBB's staff, registered-users / verified-users its members,
     * unverified-users those still to confirm, banned-users the banned;
     * cid:<n>:privileges:… groups are a category's permissions.
     */
    public static function groups(array $cfg): array
    {
        $names = function (\Redis $r): array {
            $all = $r->zRange('groups:createtime', 0, -1) ?: [];
            sort($all);

            return $all;
        };
        $roles = ['administrators' => 'admin', 'global moderators' => 'mod', 'registered-users' => 'member', 'verified-users' => 'member',
            'unverified-users' => 'pending', 'banned-users' => 'banned', 'guests' => 'guest', 'spiders' => 'bots'];

        return [
            'groups' => function (Ctx $ctx) use ($cfg, $names, $roles) {
                $r = self::redis($cfg);
                $out = [];
                foreach (self::hashes($r, self::keys('group:', $names($r))) as $name => $g) {
                    $out[] = [
                        'id' => self::groupId((string) $name), 'name' => (string) ($g['name'] ?? $name), 'color' => $g['labelColor'] ?? null,
                        'role' => $roles[strtolower((string) $name)] ?? (preg_match('/^cid:\d+:privileges:/', (string) $name) ? 'permissions' : null),
                    ];
                }

                return $out;
            },
            // A page of each group's members in turn: the cursor is [group, offset].
            'members' => function ($after, int $limit, Ctx $ctx) use ($cfg, $names) {
                $r = self::redis($cfg);
                $all = $names($r);
                [$g, $off] = is_array($after) ? $after + [0, 0] : [0, 0];
                $pairs = [];
                while (isset($all[$g]) && count($pairs) < $limit) {
                    $uids = $r->zRange('group:'.$all[$g].':members', $off, $off + $limit - count($pairs) - 1) ?: [];
                    foreach ($uids as $uid) {
                        $pairs[] = [$uid, self::groupId((string) $all[$g])];
                    }
                    $off += count($uids);
                    if (count($pairs) < $limit) {
                        [$g, $off] = [$g + 1, 0];
                    }
                }

                return ['cursor' => [$g, $off], 'rows' => count($pairs), 'done' => ! isset($all[$g]), 'pairs' => $pairs];
            },
        ];
    }

    /** A group's name is its id, hashed when it is longer than the id map holds. */
    private static function groupId(string $name): string
    {
        return strlen($name) <= 64 ? $name : 'h:'.sha1($name);
    }

    /**
     * Profile photos: `uploadedpicture` (or `picture`), an address under
     * /assets/uploads/profile/ or elsewhere. Birthdays: `birthday`
     * "mm/dd/yyyy".
     */
    public static function profiles(array $cfg): array
    {
        return [
            'count' => fn () => (int) self::redis($cfg)->zCard('users:joindate'),
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($cfg) {
                $r = self::redis($cfg);
                $uids = $r->zRange('users:joindate', $after, $after + $limit - 1) ?: [];
                $out = [];
                foreach (self::hashes($r, self::keys('user:', $uids)) as $uid => $u) {
                    $pic = trim((string) (($u['uploadedpicture'] ?? '') ?: ($u['picture'] ?? '')));
                    $path = (string) parse_url($pic, PHP_URL_PATH);
                    $avatar = $pic === '' ? null : ['url' => $pic] + (preg_match('#/assets/uploads/(.+)$#', $path, $m) ? ['root' => 'public/uploads', 'path' => rawurldecode($m[1])] : []);
                    $out[] = ['uid' => $uid, 'avatar' => $avatar, 'birthday' => Profiles::date((string) ($u['birthday'] ?? ''), 'mdy')];
                }

                return ['cursor' => $after + count($uids), 'rows' => count($uids), 'done' => count($uids) < $limit, 'profiles' => $out];
            },
        ];
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
                    $cats = self::hashes($r, self::keys('category:', $cids));
                    $map = [];
                    $n = 0;
                    foreach ($cids as $cid) {
                        $c = $cats[(string) $cid];
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
                    $users = self::hashes($r, self::keys('user:', $uids));
                    $map = [];
                    $n = $skip = 0;
                    foreach ($uids as $uid) {
                        $u = $users[(string) $uid];
                        if (! $u) {
                            $skip++;

                            continue;
                        }
                        /*
                         * 🚨 NodeBB 1.19+ keeps only a CONFIRMED address on
                         * user:{uid}, so a member who never confirmed has none,
                         * and was dropped — their posts lost their author. They
                         * come with a placeholder address instead, unconfirmed.
                         */
                        $email = trim((string) ($u['email'] ?? ''));
                        $confirmed = $email !== '' && ($u['email:confirmed'] ?? '1') !== '0';
                        $email = $email !== '' ? $email : "nodebb-{$uid}@users.invalid";
                        // banned:expire is ms, 0 (or missing) for a ban with no end.
                        $expire = (int) ($u['banned:expire'] ?? 0);
                        $banned = ($u['banned'] ?? '0') === '1' ? ($expire > 0 ? self::ts($expire) : Carbon::create(2038, 1, 1)) : null;

                        try {
                            // bcrypt of the password, or of its sha512 where NodeBB "shaWrapped" it.
                            $hash = (string) ($u['password'] ?? '');
                            $wrapped = (int) ($u['password:shaWrapped'] ?? 0) === 1;
                            $map[$uid] = Dst::user(Src::username($u['username'] ?? null, (int) $uid), $email, $wrapped ? null : $hash, self::ts($u['joindate'] ?? null), null, $wrapped && $hash !== '' ? ['scheme' => 'nodebb', 'hash' => $hash, 'salt' => null] : null, $confirmed, $banned, display: $u['username'] ?? null);
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
                    $hashes = self::hashes($r, self::keys('topic:', $tids));
                    $uids = $cids = $mainKeys = [];
                    $now = Carbon::now()->getTimestamp() * 1000;
                    foreach ($hashes as $tid => $t) {
                        /*
                         * Not on the board: deleted, or scheduled (NodeBB
                         * publishes a topic whose timestamp is still to come
                         * when that time arrives).
                         */
                        if (! $t || ($t['deleted'] ?? '0') === '1' || (int) ($t['timestamp'] ?? 0) > $now) {
                            $hashes[$tid] = [];

                            continue;
                        }
                        $uids[] = $t['uid'] ?? null;
                        $cids[(string) ($t['cid'] ?? '')] = true;
                        if (($t['mainPid'] ?? '') !== '') {
                            $mainKeys[$tid] = 'post:'.$t['mainPid'];
                        }
                    }
                    // 🚨 A disabled category is not imported, so its topics came across untagged and public.
                    $disabled = [];
                    foreach (self::hashes($r, self::keys('category:', array_filter(array_keys($cids), fn ($k) => $k !== ''))) as $cid => $c) {
                        if (($c['disabled'] ?? '0') === '1') {
                            $disabled[(string) $cid] = true;
                        }
                    }
                    $mains = self::hashes($r, $mainKeys);
                    $bodies = [];
                    foreach ($mains as $tid => $post) {
                        $uids[] = $post['uid'] ?? null;
                        $bodies[$tid] = Src::markdown($post['content'] ?? '', ['mentions' => true]) ?: '<p></p>';
                    }
                    $userMap = $ctx->mapGet('user', $uids);
                    $tagMap = $hasTags ? $ctx->mapGet('tag', array_keys($cids)) : [];
                    $files = Attachments::batch(self::attachments(), $ctx, []);
                    $bodies = $files->finish($bodies);
                    Dst::prepare($ctx, $bodies);
                    $topicMap = $mainMap = $postMap = [];
                    $n = 0;
                    foreach ($tids as $tid) {
                        $t = $hashes[(string) $tid];
                        if (! $t || isset($disabled[(string) ($t['cid'] ?? '')])) {
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
                        if (isset($mains[(string) $tid])) {
                            $post = $mains[(string) $tid];
                            $postMap[$mainPid] = Dst::post($did, 1, $userMap[(string) ($post['uid'] ?? '')] ?? $uid, $bodies[(string) $tid], self::ts($post['timestamp'] ?? ($t['timestamp'] ?? null)));
                            $files->link($tid, $postMap[$mainPid]);
                            Dst::ref('post', (string) $mainPid, $postMap[$mainPid]);
                            $mainMap[$mainPid] = $did;
                        } else {
                            Dst::post($did, 1, $uid, '<p></p>', $created);
                        }
                        Dst::finalizeDiscussion($did);
                        $n++;
                    }
                    $ctx->mapPut('topic', $topicMap);
                    $ctx->mapPut('main', $mainMap);
                    $ctx->mapPut('post', $postMap); // /post/{pid} redirects to it

                    return ['cursor' => $off + count($tids), 'processed' => count($tids), 'done' => count($tids) < $limit, 'summary' => $files->done(['topics' => $n, 'posts' => $n])];
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
                    $hashes = self::hashes($r, self::keys('post:', $pids));
                    $tids = $uids = [];
                    foreach ($hashes as $post) {
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
                        if ($hashes[(string) $pid] && ! isset($mainMap[(string) $pid])) {
                            $bodies[$pid] = Src::markdown($hashes[(string) $pid]['content'] ?? '', ['mentions' => true]) ?: '<p></p>';
                        }
                    }
                    $files = Attachments::batch(self::attachments(), $ctx, []);
                    $bodies = $files->finish($bodies);
                    Dst::prepare($ctx, $bodies);
                    $touched = [];
                    $n = 0;
                    // NodeBB orders posts by pid, not by topic, so one batch can span
                    // dozens of discussions. Fetch every starting post number in a
                    // single grouped query instead of one MAX() per discussion.
                    $counts = self::maxPostNumbers(array_values(array_unique(array_filter(
                        array_map(fn ($pid) => $topicMap[(string) ($hashes[(string) $pid]['tid'] ?? '')] ?? null, $pids)
                    ))));
                    $ids = [];
                    foreach ($pids as $pid) {
                        $post = $hashes[(string) $pid];
                        if (! $post || ($post['deleted'] ?? '0') === '1' || isset($mainMap[(string) $pid])) {
                            continue; // deleted, or already imported as a topic's first post
                        }
                        $did = $topicMap[(string) ($post['tid'] ?? '')] ?? null;
                        if (! $did) {
                            continue;
                        }
                        $counts[$did] ??= 0;

                        try {
                            $ids[$pid] = Dst::post($did, ++$counts[$did], $userMap[(string) ($post['uid'] ?? '')] ?? null, $bodies[$pid], self::ts($post['timestamp'] ?? null));
                            $files->link($pid, $ids[$pid]);
                            Dst::ref('post', (string) $pid, $ids[$pid]);
                            $n++;
                            $touched[$did] = true;
                        } catch (\Throwable) {
                            $counts[$did]--;
                        }
                    }
                    $ctx->mapPut('post', $ids);
                    foreach (array_keys($touched) as $did) {
                        Dst::finalizeDiscussion((int) $did);
                    }

                    return ['cursor' => $off + count($pids), 'processed' => count($pids), 'done' => count($pids) < $limit, 'summary' => $files->done(['posts' => $n])];
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
