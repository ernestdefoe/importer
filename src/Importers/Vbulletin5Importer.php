<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * vBulletin 5 / 6 (node architecture) → Flarum.
 *
 * Everything is a `node` typed by contenttype.class:
 *   class 'Channel'                       → a forum (tag)
 *   'Text' whose parent is a Channel      → a thread; its own text.rawtext is post #1
 *   'Text' whose parent is a thread node  → a reply
 * Bodies live in text.rawtext as BBCode (htmlstate='on' means raw HTML).
 *
 * Passwords: vBulletin 5 stores the actual login credential in `user.token`
 * (paired with a `user.scheme` label) rather than a dedicated password
 * column. Modern accounts use `blowfish:*` (bcrypt) or `argon2id:::`, both
 * of which PHP's own password_verify() understands natively — those hashes
 * are copied straight into Flarum's password column via Src::password(),
 * so the account's existing password keeps working unchanged after import.
 * Older accounts still on vBulletin's legacy salted-MD5 scheme (only
 * migrated to a modern scheme the next time that account logs in) can't be
 * converted the same way — a hash can't be reversed back into the
 * plaintext needed to re-hash it — so those get a random password instead
 * and the user has to reset it via Flarum's normal "forgot password" flow.
 * In practice, accounts still stuck on the legacy scheme tend to be ones
 * that simply haven't been used in a very long time, since vBulletin only
 * upgrades an account's hash on a successful login.
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
    private static function channelNodeIds($conn, string $p, array $channelTypeIds): array
    {
        if (! $channelTypeIds) {
            return [];
        }
        $ids = $conn->table($p . 'node')->whereIn('contenttypeid', $channelTypeIds)->pluck('nodeid')->map(fn ($v) => (int) $v)->all();

        if (! $conn->getSchemaBuilder()->hasTable($p . 'routenew')) {
            return $ids;
        }
        $forumIds = $conn->table($p . 'routenew')
            ->where('class', 'vB5_Route_Channel')
            ->where(function ($q) {
                $q->where('prefix', 'forum')->orWhere('prefix', 'like', 'forum/%');
            })
            ->pluck('contentid')->map(fn ($v) => (int) $v)->unique()->all();

        return $forumIds ? array_values(array_intersect($ids, $forumIds)) : $ids;
    }

    private static function body(?string $raw, ?string $htmlstate, ?\Closure $attachmentResolver = null): string
    {
        $raw = (string) $raw;

        return $htmlstate === 'on' ? Src::sanitizeHtml($raw) : Bbcode::toHtml($raw, ['attachment' => $attachmentResolver]);
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
    private static function attachmentResolver($conn, string $p): \Closure
    {
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $cache = [];

        return function (string $srcId) use ($conn, $p, $imageExts, &$cache): ?array {
            if (array_key_exists($srcId, $cache)) {
                return $cache[$srcId];
            }
            if (! ctype_digit($srcId)) {
                return $cache[$srcId] = null;
            }
            $row = $conn->table($p . 'attach')->where('nodeid', (int) $srcId)->first(['filedataid', 'filename']);
            if (! $row) {
                return $cache[$srcId] = null;
            }
            $file = $conn->table($p . 'filedata')->where('filedataid', $row->filedataid)->first(['filedata', 'extension']);
            if (! $file || ! $file->filedata) {
                return $cache[$srcId] = null;
            }
            $url = Dst::storeAsset((string) $file->filedata, (string) ($row->filename ?: ('attachment.' . $file->extension)));
            if (! $url) {
                return $cache[$srcId] = null;
            }

            return $cache[$srcId] = [
                'url' => $url,
                'filename' => (string) $row->filename,
                'isImage' => in_array(strtolower((string) $file->extension), $imageExts, true),
            ];
        };
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
            'avatars' => $sb->hasTable($p . 'customavatar') ? (int) $conn->table($p . 'customavatar')->count() : 0,
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
                    $channelIds = self::channelNodeIds($conn, $p, $channelTypeIds);
                    if (! $channelIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    // The whole forum tree is small (rarely more than a few hundred
                    // nodes) — recomputing placement each batch is cheap and avoids
                    // needing to persist it across the many short step requests.
                    $allNodes = $conn->table($p . 'node')->whereIn('nodeid', $channelIds)->get(['nodeid', 'parentid', 'title'])->all();
                    $placement = self::tagPlacement($allNodes);

                    $rowsRaw = $conn->table($p . 'node')->whereIn('contenttypeid', $channelTypeIds)->whereIn('nodeid', $channelIds)->where('nodeid', '>', (int) $cursor)->orderBy('nodeid')->limit($limit)->get();
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
                        $name = trim((string) ($c->title ?? '')) ?: ('Channel ' . $c->nodeid);
                        if ($place['namePrefix'] !== '') {
                            $name .= ' (' . $place['namePrefix'] . ')';
                        }
                        $parentTagId = $place['parentSrcId'] !== null
                            ? ($parentTagMap[(string) $place['parentSrcId']] ?? $map[$place['parentSrcId']] ?? null)
                            : null;
                        // Safety net: if the parent tag genuinely can't be resolved (e.g.
                        // it lands in a later batch because of an out-of-order nodeid),
                        // fall back to primary rather than creating an orphaned tag with
                        // a parent_id that doesn't exist.
                        $isPrimary = $place['parentSrcId'] === null || $parentTagId === null;
                        $tagId = Dst::tag($name, Src::tagSlug($name, (int) $c->nodeid), $c->description ?? null, null, (int) ($c->displayorder ?? 0), $isPrimary, $isPrimary ? null : $parentTagId);
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
                        $lastSeen = ((int) ($u->lastactivity ?? 0)) > 0 ? Src::ts($u->lastactivity) : (((int) ($u->lastvisit ?? 0)) > 0 ? Src::ts($u->lastvisit) : null);
                        // vBulletin 5 stores the login credential itself in `token`
                        // (a bcrypt/$2y$ or argon2id hash — both understood natively
                        // by PHP's password_verify(), so Src::password() can copy
                        // them straight across) alongside a `scheme` label (only
                        // used here to skip the lookup cost for legacy accounts,
                        // Src::password() re-derives the same decision from the
                        // hash's own prefix regardless).
                        $passwordHash = ($u->scheme ?? '') !== 'legacy' ? ($u->token ?? null) : null;
                        try {
                            $uid = Dst::user(Src::username($name !== '' ? $name : null, (int) $u->userid), $email, $passwordHash, Src::ts($u->joindate ?? null), $lastSeen);
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
            new Phase('avatars', 'Importing avatars…',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $cfg) {
                    $conn = $ctx->src();
                    if (! $conn->getSchemaBuilder()->hasTable($p . 'customavatar')) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $avatarDir = rtrim((string) ($cfg['avatar_path'] ?? ''), '/');
                    $rows = $conn->table($p . 'customavatar')->where('userid', '>', (int) $cursor)->orderBy('userid')->limit($limit)->get(['userid', 'filedata', 'filename']);
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $n = $skip = 0;
                    foreach ($rows as $row) {
                        $cursor = $row->userid;
                        $uid = $userMap[(string) $row->userid] ?? null;
                        $bytes = (string) ($row->filedata ?? '');
                        if ($bytes === '' && $avatarDir !== '' && ($row->filename ?? '')) {
                            $path = $avatarDir . '/' . basename((string) $row->filename);
                            $bytes = is_file($path) ? (@file_get_contents($path) ?: '') : '';
                        }
                        if ($uid && Dst::avatar((int) $uid, $bytes)) {
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
            new Phase('topics', 'Importing topics…',
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
                    $attachmentResolver = self::attachmentResolver($conn, $p);
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
                        Dst::post($did, 1, $uid, self::body($node->rawtext ?? '', $node->htmlstate ?? '', $attachmentResolver) ?: '<p></p>', $created);
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
                    $attachmentResolver = self::attachmentResolver($conn, $p);
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
                            Dst::post($did, ++$carry['num'], $userMap[(string) $node->userid] ?? null, self::body($node->rawtext ?? '', $node->htmlstate ?? '', $attachmentResolver) ?: '<p></p>', $created);
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
}
