<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * vBulletin 5 / 6 (node architecture) → Flarum.
 *
 * Everything is a `node` typed by contenttype.class:
 *   class 'Channel'                       → a forum (tag)
 *   'Text' whose parent is a Channel      → a thread; its own text.rawtext is post #1
 *   'Text' whose parent is a thread node  → a reply
 * Bodies live in text.rawtext as BBCode (htmlstate='on' means raw HTML). vB tokens
 * aren't portable, so members reset. Delegated to from VbulletinImporter.
 */
class Vbulletin5Importer
{
    /** @return array{0:int[],1:int[]} [channelTypeIds, textTypeIds] */
    private static function typeIds($conn, string $p): array
    {
        $channel = $text = [];
        foreach ($conn->table($p . 'contenttype')->get(['contenttypeid', 'class']) as $ct) {
            if ((string) $ct->class === 'Channel') {
                $channel[] = (int) $ct->contenttypeid;
            } elseif ((string) $ct->class === 'Text') {
                $text[] = (int) $ct->contenttypeid;
            }
        }

        return [$channel, $text];
    }

    /** @return int[] */
    private static function channelNodeIds($conn, string $p, array $channelTypeIds): array
    {
        return $channelTypeIds
            ? $conn->table($p . 'node')->whereIn('contenttypeid', $channelTypeIds)->pluck('nodeid')->map(fn ($v) => (int) $v)->all()
            : [];
    }

    private static function body(?string $raw, ?string $htmlstate): string
    {
        $raw = (string) $raw;

        return $htmlstate === 'on' ? Src::sanitizeHtml($raw) : Bbcode::toHtml($raw);
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $sb = $conn->getSchemaBuilder();
        foreach (['node', 'text', 'contenttype', 'user'] as $req) {
            if (! $sb->hasTable($p . $req)) {
                throw new \RuntimeException("This doesn't look like a vBulletin 5 database (missing “{$p}{$req}”).");
            }
        }
        [$channelTypeIds, $textTypeIds] = self::typeIds($conn, $p);
        $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p . 'user')->count(),
            'categories' => count($channelIds),
            'topics' => $textTypeIds && $channelIds
                ? (int) $conn->table($p . 'node')->whereIn('contenttypeid', $textTypeIds)->whereIn('parentid', $channelIds)->count()
                : 0,
            'posts' => $textTypeIds ? (int) $conn->table($p . 'node')->whereIn('contenttypeid', $textTypeIds)->count() : 0,
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing channels…',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $conn = $ctx->src();
                    [$channelTypeIds] = self::typeIds($conn, $p);
                    if (! $channelTypeIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $conn->table($p . 'node')->whereIn('contenttypeid', $channelTypeIds)->where('nodeid', '>', (int) $cursor)->orderBy('nodeid')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->nodeid;
                        $name = trim((string) ($c->title ?? '')) ?: ('Channel ' . $c->nodeid);
                        $map[$c->nodeid] = Dst::tag($name, Src::tagSlug($name, (int) $c->nodeid), $c->description ?? null, null, (int) ($c->displayorder ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table($p . 'user')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    $hasDisplayName = $conn->getSchemaBuilder()->hasColumn($p . 'user', 'displayname');
                    $rows = $conn->table($p . 'user')->where('userid', '>', (int) $cursor)->orderBy('userid')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->userid;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        $name = trim((string) (($hasDisplayName ? ($u->displayname ?? null) : null) ?: $u->username ?? ''));
                        try {
                            $map[$u->userid] = Dst::user(Src::username($name !== '' ? $name : null, (int) $u->userid), $email, null, Src::ts($u->joindate ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            // Thread starters: Text nodes whose parent is a Channel. The node's own
            // text row is the discussion's first post (#1).
            new Phase('topics', 'Importing topics…',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $conn = $ctx->src();
                    [$channelTypeIds, $textTypeIds] = self::typeIds($conn, $p);
                    $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);
                    if (! $textTypeIds || ! $channelIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $conn->table($p . 'node')
                        ->join($p . 'text', $p . 'text.nodeid', '=', $p . 'node.nodeid')
                        ->whereIn($p . 'node.contenttypeid', $textTypeIds)
                        ->whereIn($p . 'node.parentid', $channelIds)
                        ->where($p . 'node.approved', 1)
                        ->where($p . 'node.nodeid', '>', (int) $cursor)
                        ->orderBy($p . 'node.nodeid')
                        ->select($p . 'node.*', $p . 'text.rawtext', $p . 'text.htmlstate')
                        ->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('parentid')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $node) {
                        $cursor = $node->nodeid;
                        $title = trim((string) ($node->title ?? '')) ?: 'Untitled';
                        $created = Src::ts($node->publishdate ?? $node->created ?? null);
                        $uid = $userMap[(string) $node->userid] ?? null;
                        $did = Dst::discussion($title, $uid, $created, (bool) ($node->sticky ?? false));
                        $map[$node->nodeid] = $did;
                        if ($hasTags && isset($tagMap[(string) $node->parentid])) {
                            Dst::attachTag($did, $tagMap[(string) $node->parentid]);
                        }
                        Dst::post($did, 1, $uid, self::body($node->rawtext ?? '', $node->htmlstate ?? '') ?: '<p></p>', $created);
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n, 'posts' => $n]];
                }
            ),

            // Replies: Text nodes whose parent is a thread starter (not a Channel).
            // They continue each discussion's numbering after the starter (#1).
            new Phase('posts', 'Importing posts…',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    [$channelTypeIds, $textTypeIds] = self::typeIds($conn, $p);
                    $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);
                    if (! $textTypeIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $cur = is_array($cursor) ? $cursor : ['nid' => 0, 'carry' => null];
                    $carry = $cur['carry'] ?? null;

                    $rows = $conn->table($p . 'node')
                        ->join($p . 'text', $p . 'text.nodeid', '=', $p . 'node.nodeid')
                        ->whereIn($p . 'node.contenttypeid', $textTypeIds)
                        ->when($channelIds, fn ($q) => $q->whereNotIn($p . 'node.parentid', $channelIds))
                        ->where($p . 'node.approved', 1)
                        ->where($p . 'node.nodeid', '>', (int) $cur['nid'])
                        ->orderBy($p . 'node.nodeid')
                        ->select($p . 'node.*', $p . 'text.rawtext', $p . 'text.htmlstate')
                        ->limit($limit)->get();

                    $topicMap = $ctx->mapGet('topic', $rows->pluck('parentid')->all());
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $db = Dst::db();
                    $n = 0;
                    foreach ($rows as $node) {
                        $cur['nid'] = (int) $node->nodeid;
                        $did = $topicMap[(string) $node->parentid] ?? null;
                        if (! $did) {
                            continue; // reply to something we didn't import as a thread
                        }
                        if (! $carry || (int) $carry['did'] !== (int) $did) {
                            if ($carry && ! empty($carry['did'])) {
                                Dst::finalizeDiscussion((int) $carry['did']);
                            }
                            $carry = ['did' => (int) $did, 'num' => (int) ($db->table('posts')->where('discussion_id', $did)->max('number') ?? 0)];
                        }
                        $created = Src::ts($node->publishdate ?? $node->created ?? null);
                        try {
                            Dst::post($did, ++$carry['num'], $userMap[(string) $node->userid] ?? null, self::body($node->rawtext ?? '', $node->htmlstate ?? '') ?: '<p></p>', $created);
                            $n++;
                        } catch (\Throwable) {
                            $carry['num']--;
                        }
                    }

                    $done = count($rows) < $limit;
                    if ($done && $carry && ! empty($carry['did'])) {
                        Dst::finalizeDiscussion((int) $carry['did']);
                    }
                    $cur['carry'] = $done ? null : $carry;

                    return ['cursor' => $cur, 'processed' => count($rows), 'done' => $done, 'summary' => ['posts' => $n]];
                }
            ),
        ], Phases::tail());
    }
}
