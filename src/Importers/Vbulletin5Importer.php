<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;

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
    /** @return array{0:int[],1:int[]} [channelTypeIds, textTypeIds] (Text and Poll) */
    private static function typeIds(ConnectionInterface $conn, string $p): array
    {
        $channel = $text = [];
        foreach ($conn->table($p.'contenttype')->get(['contenttypeid', 'class']) as $ct) {
            if ((string) $ct->class === 'Channel') {
                $channel[] = (int) $ct->contenttypeid;
            } elseif (in_array((string) $ct->class, ['Text', 'Poll'], true)) {
                // A poll thread's starter is a Poll node: Text with a poll on it.
                $text[] = (int) $ct->contenttypeid;
            }
        }

        return [$channel, $text];
    }

    /**
     * Flarum core group id (1 = Admin, 4 = Mod) for a vBulletin user, or 0 for
     * no mapping. Checks both the primary group (`usergroupid`) and every
     * secondary group (`membergroupids`, comma-separated) — a vBulletin user
     * commonly keeps "Member" as their primary group with "Moderator" only as
     * a secondary one, so the primary id alone misses real moderators/admins.
     * vBulletin system group ids are stable across installs: 6 = Administrators,
     * 5 = Super Moderators, 7 = Moderators.
     */
    private static function coreGroupFor(int $primaryGroupId, string $secondaryGroupIds = ''): int
    {
        $ids = array_filter(array_map('intval', explode(',', $secondaryGroupIds)));
        $ids[] = $primaryGroupId;
        if (in_array(6, $ids, true)) {
            return 1; // Admin
        }
        if (in_array(5, $ids, true) || in_array(7, $ids, true)) {
            return 4; // Mod
        }

        return 0;
    }

    /**
     * Flatten vBulletin's forum tree onto Flarum's 2-level tag model
     * (`is_primary` + one level of `parent_id`). vBulletin sub-forums often
     * nest 3-4 levels deep (e.g. Forum → Buurten → Belfort → Villapark);
     * Flarum's tags UI only renders a primary tag and its direct children,
     * so anything past depth 2 is re-parented onto its depth-1 ancestor with
     * the intermediate path folded into the tag's own name — e.g. depth-4
     * "Villapark" under depth-2 "Buurten" becomes secondary tag
     * "Villapark (Buurten)" directly under the depth-1 primary tag, rather
     * than silently losing which neighbourhood group it belonged to.
     *
     * The root container itself (e.g. the "Forum" node vB5 routes to the
     * bare "forum" prefix — matched by {@see channelNodeIds()} alongside its
     * real children, but it has no topics of its own) is auto-detected as
     * the one node whose parent isn't itself part of the given set, and is
     * omitted from the returned placement entirely so the caller can skip
     * creating a pointless empty tag for it.
     *
     * @param  array<int,object{nodeid:int,parentid:int,title:string}>  $nodes  every forum node, order doesn't matter
     * @return array<int,array{parentSrcId:int|null,namePrefix:string}>  keyed by nodeid, root omitted
     */
    private static function tagPlacement(array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n->nodeid] = $n;
        }
        // A node is a root if its own parent isn't part of the given set (e.g.
        // vBulletin's "Forum" container, whose parent is the Homepage node,
        // which channelNodeIds() excludes as a system pseudo-channel).
        $rootIds = [];
        foreach ($nodes as $n) {
            if (! isset($byId[$n->parentid])) {
                $rootIds[$n->nodeid] = true;
            }
        }

        $placement = [];
        foreach ($nodes as $n) {
            if (isset($rootIds[$n->nodeid])) {
                continue; // the root itself: no tag, just a hierarchy anchor
            }
            if (isset($rootIds[$n->parentid])) {
                // Direct child of a root = depth-1 = primary tag.
                $placement[$n->nodeid] = ['parentSrcId' => null, 'namePrefix' => ''];

                continue;
            }
            // Walk up, collecting ancestors (nearest first), until we reach the
            // depth-1 node (the one whose own parent is a root).
            $chain = [];
            $cur = $n;
            $guard = 0;
            while ($cur && ! isset($rootIds[$cur->parentid]) && $guard++ < 50) {
                $parent = $byId[$cur->parentid] ?? null;
                if (! $parent) {
                    break;
                }
                $chain[] = $parent;
                $cur = $parent;
            }
            // $chain is now [nearest ancestor, ..., depth-1 ancestor] (or empty if $n is itself depth-1).
            if (! $chain) {
                $placement[$n->nodeid] = ['parentSrcId' => null, 'namePrefix' => ''];

                continue;
            }
            $depth1 = end($chain);
            // Everything between depth-1 and this node (exclusive of both) folds into the name.
            $between = array_slice($chain, 0, -1);
            $prefixParts = array_reverse(array_map(fn ($a) => trim((string) $a->title), $between));
            $placement[$n->nodeid] = [
                'parentSrcId' => (int) $depth1->nodeid,
                'namePrefix' => $prefixParts ? implode(' / ', array_filter($prefixParts)) : '',
            ];
        }

        return $placement;
    }

    /**
     * Real sub-forums, excluding vBulletin's system pseudo-channels (Visitor
     * Messages, Private Messages, Albums, Reports, Infractions, Articles,
     * Social Groups, the Homepage node itself, …). Without this, every
     * profile-wall "Visitor Message" node gets imported as a titleless
     * discussion.
     *
     * vB5's route cache reliably tags every real forum with a route whose
     * prefix starts with "forum" ("forum" itself, or "forum/…" for
     * sub-forums); system channels route to "special/…", "articles",
     * "social-groups/…", "homepage", etc. When the route table is missing
     * (unusual custom installs) we fall back to the unfiltered set rather
     * than importing nothing.
     *
     * @return int[]
     */
    private static function channelNodeIds(ConnectionInterface $conn, string $p, array $channelTypeIds): array
    {
        if (! $channelTypeIds) {
            return [];
        }
        $ids = $conn->table($p.'node')->whereIn('contenttypeid', $channelTypeIds)->pluck('nodeid')->map(fn ($v) => (int) $v)->all();

        if (! $conn->getSchemaBuilder()->hasTable($p.'routenew')) {
            return $ids;
        }
        $forumIds = $conn->table($p.'routenew')
            ->where('class', 'vB5_Route_Channel')
            ->where(function ($q) {
                $q->where('prefix', 'forum')->orWhere('prefix', 'like', 'forum/%');
            })
            ->pluck('contentid')->map(fn ($v) => (int) $v)->unique()->all();

        return $forumIds ? array_values(array_intersect($ids, $forumIds)) : $ids;
    }

    /**
     * A vBulletin member's password, checked at their first sign-in.
     *
     * vBulletin 5 keeps it in `scheme` + `token`: `blowfish:10` / `argon2id`
     * tokens hash md5(password) — vBulletin md5s the password before anything
     * else — and `legacy` tokens are vBulletin 4's "md5(md5(pw) . salt) salt".
     * vBulletin 3/4 keep `password` = md5(md5(pw) . salt) and `salt`.
     *
     * Credit: Rob2dev, who mapped vBulletin 5's schemes on a real board (#4).
     */
    public static function legacy(object $u): ?array
    {
        $scheme = strtolower(trim((string) ($u->scheme ?? '')));
        $token = trim((string) ($u->token ?? ''));
        if ($token !== '' && $scheme !== '') {
            if ($scheme === 'legacy') {
                [$hash, $salt] = array_pad(preg_split('/\s+/', $token, 2) ?: [], 2, '');

                return $hash !== '' ? ['scheme' => 'vb', 'hash' => $hash, 'salt' => $salt] : null;
            }
            if (str_starts_with($scheme, 'blowfish') || str_starts_with($scheme, 'argon2')) {
                return ['scheme' => 'vb5', 'hash' => $token, 'salt' => null];
            }

            return null;
        }
        $hash = trim((string) ($u->password ?? ''));

        return $hash !== '' ? ['scheme' => 'vb', 'hash' => $hash, 'salt' => (string) ($u->salt ?? '')] : null;
    }

    private static function body(?string $raw, ?string $htmlstate, ?\Closure $attachmentResolver = null): string
    {
        $raw = (string) $raw;
        // "Allow HTML" posts may hold BBCode too; HTML is made safe in Dst.
        $html = match ($htmlstate) {
            'on' => 'raw',
            'on_nl2br' => 'nl2br',
            default => null,
        };

        return Bbcode::toHtml($raw, array_filter(['attachment' => $attachmentResolver, 'html' => $html]));
    }

    /**
     * Builds a resolver for [ATTACH]/[ATTACH=JSON] BBCode references: given a
     * vBulletin attachment node id, rehosts the file's bytes (from the
     * `filedata` BLOB, since vBulletin keeps attachment content in the
     * database — unlike its avatars, which are commonly file-based) onto
     * Flarum's own public asset disk and returns its new URL. Results are
     * cached per source id for the lifetime of one batch since the same
     * attachment can be referenced from more than one post (quotes).
     */
    private static function attachmentResolver(ConnectionInterface $conn, string $p): \Closure
    {
        $cache = [];

        return function (string $srcId) use ($conn, $p, &$cache): ?array {
            if (array_key_exists($srcId, $cache)) {
                return $cache[$srcId];
            }
            if (! ctype_digit($srcId)) {
                return $cache[$srcId] = null;
            }
            $row = $conn->table($p.'attach')->where('nodeid', (int) $srcId)->first(['filedataid', 'filename']);
            if (! $row) {
                return $cache[$srcId] = null;
            }
            $file = $conn->table($p.'filedata')->where('filedataid', $row->filedataid)->first(['filedata', 'extension']);
            if (! $file || ! $file->filedata) {
                return $cache[$srcId] = null;
            }
            $stored = Dst::storeAsset((string) $file->filedata, (string) ($row->filename ?: ('attachment.'.$file->extension)));

            return $cache[$srcId] = $stored ? $stored + ['filename' => (string) $row->filename] : null;
        };
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $sb = $conn->getSchemaBuilder();
        foreach (['node', 'text', 'contenttype', 'user'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.vbulletin5', ['table' => $p.$req]));
            }
        }
        [$channelTypeIds, $textTypeIds] = self::typeIds($conn, $p);
        $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'user')->count(),
            'categories' => count($channelIds),
            'topics' => $textTypeIds && $channelIds
                ? (int) $conn->table($p.'node')->whereIn('contenttypeid', $textTypeIds)->whereIn('parentid', $channelIds)->count()
                : 0,
            'posts' => $textTypeIds ? (int) $conn->table($p.'node')->whereIn('contenttypeid', $textTypeIds)->count() : 0,
            'avatars' => $sb->hasTable($p.'customavatar') ? (int) $conn->table($p.'customavatar')->count() : 0,
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase(
                'tags',
                'channels',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $conn = $ctx->src();
                    [$channelTypeIds] = self::typeIds($conn, $p);
                    $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);
                    if (! $channelIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    // The whole forum tree is small (rarely more than a few hundred
                    // nodes) — recomputing placement each batch is cheap and avoids
                    // needing to persist it across the many short step requests.
                    $allNodes = $conn->table($p.'node')->whereIn('nodeid', $channelIds)->get(['nodeid', 'parentid', 'title'])->all();
                    $placement = self::tagPlacement($allNodes);

                    $rowsRaw = $conn->table($p.'node')->whereIn('contenttypeid', $channelTypeIds)->whereIn('nodeid', $channelIds)->where('nodeid', '>', (int) $cursor)->orderBy('nodeid')->limit($limit)->get();
                    // The tree root (e.g. vBulletin's bare "Forum" container) is
                    // auto-excluded by tagPlacement() — skip it here too, it has no
                    // topics and would otherwise become an empty leftover tag. The
                    // cursor still advances past it below, from $rowsRaw.
                    $rows = $rowsRaw->filter(fn ($c) => isset($placement[$c->nodeid]));
                    // Primary (depth-1) tags first within this batch, so their Flarum ids
                    // exist before a secondary tag needs one as parent_id.
                    $rowsSorted = $rows->sortBy(fn ($c) => ($placement[$c->nodeid]['parentSrcId'] ?? null) === null ? 0 : 1);
                    $parentSrcIds = $rowsSorted->map(fn ($c) => $placement[$c->nodeid]['parentSrcId'] ?? null)->filter()->unique()->all();
                    $parentTagMap = $ctx->mapGet('tag', $parentSrcIds);

                    $map = [];
                    $n = 0;
                    foreach ($rowsRaw as $c) {
                        $cursor = max($cursor, $c->nodeid);
                    }
                    foreach ($rowsSorted as $c) {
                        $place = $placement[$c->nodeid] ?? ['parentSrcId' => null, 'namePrefix' => ''];
                        $name = trim((string) ($c->title ?? '')) ?: ('Channel '.$c->nodeid);
                        if ($place['namePrefix'] !== '') {
                            $name .= ' ('.$place['namePrefix'].')';
                        }
                        $parentTagId = $place['parentSrcId'] !== null
                            ? ($parentTagMap[(string) $place['parentSrcId']] ?? $map[$place['parentSrcId']] ?? null)
                            : null;
                        // Safety net: a parent tag that can't be resolved (it lands in a
                        // later batch because of an out-of-order nodeid) leaves this one a
                        // top-level tag rather than one pointing at a parent that isn't there.
                        $tagId = Dst::tag($name, Src::tagSlug($name, (int) $c->nodeid), $c->description ?? null, null, (int) ($c->displayorder ?? 0), $parentTagId);
                        $map[$c->nodeid] = $tagId;
                        if ($place['parentSrcId'] === null) {
                            // Make this batch's own primary tags resolvable as a parent
                            // for a secondary tag later in the same batch.
                            $parentTagMap[(string) $c->nodeid] = $tagId;
                        }
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rowsRaw), 'done' => count($rowsRaw) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'user')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    $hasDisplayName = $conn->getSchemaBuilder()->hasColumn($p.'user', 'displayname');
                    $rows = $conn->table($p.'user')->where('userid', '>', (int) $cursor)->orderBy('userid')->limit($limit)->get();
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
                        $lastSeen = ((int) ($u->lastactivity ?? 0)) > 0 ? Src::ts($u->lastactivity) : (((int) ($u->lastvisit ?? 0)) > 0 ? Src::ts($u->lastvisit) : null);
                        try {
                            $uid = Dst::user(Src::username($name !== '' ? $name : null, (int) $u->userid), $email, null, Src::ts($u->joindate ?? null), $lastSeen, self::legacy($u));
                            $map[$u->userid] = $uid;
                            // vBulletin's "Administrators"/"Super Moderators"/"Moderators" system
                            // groups map onto Flarum's built-in Admin/Mod groups. Everything else
                            // (custom titles, awaiting-confirmation states, banned, …) has no
                            // Flarum equivalent and is intentionally left alone.
                            Dst::assignCoreGroup($uid, self::coreGroupFor((int) ($u->usergroupid ?? 0), (string) ($u->membergroupids ?? '')));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            // Custom (uploaded) avatars. vBulletin 5 stores the image bytes
            // either as a BLOB in `customavatar.filedata`, or — if the admin
            // configured file-based storage (the common case) — only the
            // filename there, with the actual bytes on disk. When the blob
            // is empty and --avatar-path/`avatar_path` config points at that
            // directory, we read the file `customavatar.filename` names.
            // vBulletin "preset gallery" avatars (mo_vb3_avatar) aren't user
            // uploads and have no per-user image data, so they're skipped.
            new Phase(
                'avatars',
                'avatars',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $cfg) {
                    $conn = $ctx->src();
                    if (! $conn->getSchemaBuilder()->hasTable($p.'customavatar')) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $avatarDir = rtrim((string) ($cfg['avatar_path'] ?? ''), '/');
                    $rows = $conn->table($p.'customavatar')->where('userid', '>', (int) $cursor)->orderBy('userid')->limit($limit)->get(['userid', 'filedata', 'filename']);
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $n = $skip = 0;
                    foreach ($rows as $row) {
                        $cursor = $row->userid;
                        $uid = $userMap[(string) $row->userid] ?? null;
                        $bytes = (string) ($row->filedata ?? '');
                        if ($bytes === '' && $avatarDir !== '' && ($row->filename ?? '')) {
                            $path = $avatarDir.'/'.basename((string) $row->filename);
                            $bytes = is_file($path) ? (@file_get_contents($path) ?: '') : '';
                        }
                        if ($uid && Dst::avatarBytes((int) $uid, $bytes)) {
                            $n++;
                        } else {
                            $skip++;
                        }
                    }

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['avatars' => $n, 'skipped' => $skip]];
                }
            ),

            // Thread starters: Text nodes whose parent is a Channel. The node's own
            // text row is the discussion's first post (#1).
            new Phase(
                'topics',
                'topics',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags, $cfg) {
                    $conn = $ctx->src();
                    [$channelTypeIds, $textTypeIds] = self::typeIds($conn, $p);
                    $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);
                    if (! $textTypeIds || ! $channelIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $maxTopics = (int) ($cfg['max_topics'] ?? 0);
                    if ($maxTopics > 0) {
                        $already = (int) Dst::db()->table('importer_map')->where('run_id', $ctx->runId)->where('kind', 'topic')->count();
                        if ($already >= $maxTopics) {
                            return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                        }
                        $limit = min($limit, $maxTopics - $already);
                    }
                    $rows = $conn->table($p.'node')
                        ->join($p.'text', $p.'text.nodeid', '=', $p.'node.nodeid')
                        ->whereIn($p.'node.contenttypeid', $textTypeIds)
                        ->whereIn($p.'node.parentid', $channelIds)
                        ->where($p.'node.approved', 1)
                        ->where($p.'node.nodeid', '>', (int) $cursor)
                        ->orderBy($p.'node.nodeid')
                        ->select($p.'node.*', $p.'text.rawtext', $p.'text.htmlstate')
                        ->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('parentid')->all()) : [];
                    $attachmentResolver = self::attachmentResolver($conn, $p);
                    $bodies = [];
                    foreach ($rows as $node) {
                        $bodies[$node->nodeid] = self::body($node->rawtext ?? '', $node->htmlstate ?? '', $attachmentResolver) ?: '<p></p>';
                    }
                    Dst::prepare($ctx, $bodies);
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
                        Dst::post($did, 1, $uid, $bodies[$node->nodeid], $created);
                        // Finalize immediately: topics with zero replies are never
                        // visited again by the posts phase, so without this their
                        // first_post_id/last_post_id/comment_count stay NULL/0 and
                        // Flarum renders them as an empty "Untitled" discussion.
                        Dst::finalizeDiscussion($did);
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n, 'posts' => $n]];
                }
            ),

            // Replies: Text nodes whose parent is a thread starter (not a Channel).
            // They continue each discussion's numbering after the starter (#1).
            new Phase(
                'posts',
                'posts',
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

                    $rows = $conn->table($p.'node')
                        ->join($p.'text', $p.'text.nodeid', '=', $p.'node.nodeid')
                        ->whereIn($p.'node.contenttypeid', $textTypeIds)
                        ->when($channelIds, fn ($q) => $q->whereNotIn($p.'node.parentid', $channelIds))
                        ->where($p.'node.approved', 1)
                        ->where($p.'node.nodeid', '>', (int) $cur['nid'])
                        ->orderBy($p.'node.nodeid')
                        ->select($p.'node.*', $p.'text.rawtext', $p.'text.htmlstate')
                        ->limit($limit)->get();

                    $topicMap = $ctx->mapGet('topic', $rows->pluck('parentid')->all());
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $attachmentResolver = self::attachmentResolver($conn, $p);
                    $bodies = [];
                    foreach ($rows as $node) {
                        if (isset($topicMap[(string) $node->parentid])) {
                            $bodies[$node->nodeid] = self::body($node->rawtext ?? '', $node->htmlstate ?? '', $attachmentResolver) ?: '<p></p>';
                        }
                    }
                    Dst::prepare($ctx, $bodies);
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
                            Dst::post($did, ++$carry['num'], $userMap[(string) $node->userid] ?? null, $bodies[$node->nodeid], $created);
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
        ], Phases::tail(), ! empty($cfg['prune_empty_tags']) ? Phases::pruneEmptyTags() : []);
    }
    /**
     * vBulletin 5/6 private messages are nodes too: `privatemessage` marks a
     * node as one (`msgtype` 'message'; 'notification' and 'request' are the
     * system's own), its text is in `text`, a reply's `starter` is the first
     * message, and `sentto` holds a row per participant's copy (`msgread`,
     * `deleted` once they deleted it).
     */
    public static function privateMessages(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'privatemessage') && $conn->getSchemaBuilder()->hasTable($p.'sentto');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'privatemessage')->where('msgtype', 'message')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'privatemessage')
                    ->join($p.'node', $p.'node.nodeid', '=', $p.'privatemessage.nodeid')
                    ->leftJoin($p.'text', $p.'text.nodeid', '=', $p.'privatemessage.nodeid')
                    ->where($p.'privatemessage.msgtype', 'message')
                    ->where($p.'privatemessage.nodeid', '>', $after)
                    ->orderBy($p.'privatemessage.nodeid')
                    ->select($p.'node.*', $p.'text.rawtext', $p.'text.htmlstate')
                    ->limit($limit)->get();
                $copies = [];
                $deleted = $conn->getSchemaBuilder()->hasColumn($p.'sentto', 'deleted');
                foreach ($rows->isEmpty() ? [] : $conn->table($p.'sentto')->whereIn('nodeid', $rows->pluck('nodeid')->all())->get() as $c) {
                    $copies[$c->nodeid][] = $c;
                }
                $out = [];
                foreach ($rows as $n) {
                    $after = (int) $n->nodeid;
                    $mine = $copies[$n->nodeid] ?? [];
                    $people = array_merge([$n->userid], array_map(fn ($c) => $c->userid, $mine));
                    $keep = array_map(fn ($c) => $c->userid, array_filter($mine, fn ($c) => ! $deleted || ! (int) $c->deleted));
                    if (! in_array((int) $n->userid, array_map(fn ($c) => (int) $c->userid, $mine), true)) {
                        $keep[] = $n->userid; // no copy of their own to delete
                    }
                    $out[] = [
                        'id' => (string) $n->nodeid,
                        'conv' => PrivateMessages::conv((string) ((int) ($n->starter ?? 0) ?: $n->nodeid), $people),
                        'title' => (string) ($n->title ?? ''),
                        'uid' => $n->userid,
                        'people' => $people,
                        'keep' => $keep,
                        'unread' => array_map(fn ($c) => $c->userid, array_filter($mine, fn ($c) => ! (int) ($c->msgread ?? 1))),
                        'html' => self::body($n->rawtext ?? '', $n->htmlstate ?? ''),
                        'at' => Src::ts($n->publishdate ?? $n->created ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * A poll is its thread's starter node (contenttype Poll, a kind of Text):
     * `poll` and `polloption` are keyed by that nodeid, the question is the
     * node's title, and `pollvote` names the member and option.
     */
    public static function polls(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'poll') && $conn->getSchemaBuilder()->hasTable($p.'polloption');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'poll')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                $q = $conn->table($p.'poll')->join($p.'node', $p.'node.nodeid', '=', $p.'poll.nodeid')
                    ->select($p.'poll.*', $p.'node.title', $p.'node.userid', $p.'node.created');
                [$rows, $cursor, $done, $n] = Polls::page($q, $p.'poll.nodeid', $after, $limit, $ctx);
                $ids = $rows->pluck('nodeid')->all();
                $options = $votes = [];
                foreach ($ids ? $conn->table($p.'polloption')->whereIn('nodeid', $ids)->orderBy('polloptionid')->get(['polloptionid', 'nodeid', 'title', 'votes']) : [] as $o) {
                    $options[$o->nodeid][(string) $o->polloptionid] = ['text' => Polls::plain(Bbcode::toHtml((string) $o->title)), 'count' => (int) $o->votes];
                }
                foreach ($ids && $conn->getSchemaBuilder()->hasTable($p.'pollvote') ? $conn->table($p.'pollvote')->whereIn('nodeid', $ids)->get(['nodeid', 'polloptionid', 'userid', 'votedate']) : [] as $v) {
                    $votes[$v->nodeid][] = [$v->userid, (string) $v->polloptionid, Polls::at($v->votedate)];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $at = Src::ts($poll->created ?? null);
                    $out[] = [
                        'id' => (string) $poll->nodeid,
                        'topic' => (string) $poll->nodeid,
                        'question' => Polls::plain(Bbcode::toHtml((string) $poll->title)),
                        'uid' => $poll->userid,
                        'at' => $at,
                        'ends' => Polls::at($poll->timeout ?? 0)
                            ?? ((int) ($poll->active ?? 1) ? null : Polls::closedAt(Polls::at($poll->lastvote ?? 0), $at)),
                        'max' => (int) ($poll->multiple ?? 0) ? 0 : 1,
                        'public' => (bool) ($poll->public ?? false),
                        'change' => false,
                        'options' => $options[$poll->nodeid] ?? [],
                        'votes' => $votes[$poll->nodeid] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
