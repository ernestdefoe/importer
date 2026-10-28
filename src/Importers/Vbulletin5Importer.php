<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * vBulletin 5 / 6 (node architecture) → Flarum.
 *
 * Everything is a `node` typed by contenttype.class:
 *   class 'Channel'                       → a forum (tag)
 *   'Text' whose parent is a Channel      → a thread; its own text.rawtext is post #1
 *     (or a Poll, Gallery, Video, Link or Event: kinds of Text)
 *   'Text' whose `starter` is a thread    → a reply, or a comment on one
 * Bodies live in text.rawtext as BBCode (htmlstate='on' means raw HTML). vB tokens
 * aren't portable, so members reset. Delegated to from VbulletinImporter.
 */
class Vbulletin5Importer
{
    /**
     * Content types a thread (or a reply) can be: Text and the kinds built on
     * it, whose body is in `text` as well. A poll thread's starter is a Poll
     * node; 🚨 Gallery, Video, Link and Event threads were dropped with all
     * their replies. Photo and Attach nodes have no `text` row and are never
     * posts of their own.
     */
    private const POST_CLASSES = ['Text', 'Poll', 'Gallery', 'Video', 'Link', 'Event'];

    /** @return array{0:int[],1:int[]} [channelTypeIds, postTypeIds] */
    private static function typeIds(ConnectionInterface $conn, string $p): array
    {
        $channel = $text = [];
        foreach ($conn->table($p.'contenttype')->get(['contenttypeid', 'class']) as $ct) {
            if ((string) $ct->class === 'Channel') {
                $channel[] = (int) $ct->contenttypeid;
            } elseif (in_array((string) $ct->class, self::POST_CLASSES, true)) {
                $text[] = (int) $ct->contenttypeid;
            }
        }

        return [$channel, $text];
    }

    /** vBulletin stores titles and names HTML-escaped ("Q&amp;A"). */
    public static function plain(?string $s): string
    {
        return trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Bans for a batch of members, from `userban` (vBulletin 3 to 6): userid
     * => when it lifts (0 = never). One query per batch.
     *
     * @return array<string,int>
     */
    public static function bans(ConnectionInterface $conn, string $p, array $userIds): array
    {
        if (! $userIds || ! $conn->getSchemaBuilder()->hasTable($p.'userban')) {
            return [];
        }
        $out = [];
        foreach ($conn->table($p.'userban')->whereIn('userid', $userIds)->get(['userid', 'liftdate']) as $b) {
            $out[(string) $b->userid] = (int) $b->liftdate;
        }

        return $out;
    }

    /**
     * [confirmed, suspendedUntil] for a member. System group ids are the same
     * on every vBulletin: 3 = awaiting email confirmation, 4 = awaiting
     * moderation, 8 = banned. 🚨 Banned members and never-activated accounts
     * used to arrive as ordinary members who could sign straight in.
     *
     * @param  array<string,int>  $bans  {@see bans()}
     * @return array{0:bool,1:?Carbon}
     */
    public static function standing(object $u, array $bans): array
    {
        $group = (int) ($u->usergroupid ?? 0);
        $lift = $bans[(string) $u->userid] ?? null;
        $until = null;
        if ($lift !== null || $group === 8) {
            // No end date (0), or a member moved into Banned by hand: permanent.
            $until = $lift ? Carbon::createFromTimestamp($lift) : Carbon::create(2038, 1, 1);
        }

        return [! in_array($group, [3, 4], true), $until];
    }

    /**
     * Neither guests (usergroup 1) nor registered members (2) could see this
     * forum: a staff forum. `forumpermissions` bit 1 is canview; a forum with
     * no row of its own for a group inherits its nearest ancestor's, and one
     * with none anywhere the group's default.
     *
     * @param  int[]  $chain  the forum, then its parent, … up to the root
     * @param  array<int,array<int,int>>  $perm  forum id => group id => bits
     * @param  array<int,int|null>  $defaults  group id => its default bits (null: unknown, so visible)
     */
    public static function restricted(array $chain, array $perm, array $defaults): bool
    {
        foreach ([1, 2] as $group) {
            $bits = $defaults[$group] ?? null;
            foreach ($chain as $id) {
                if (isset($perm[$id][$group])) {
                    $bits = $perm[$id][$group];

                    break;
                }
            }
            if ($bits === null || ($bits & 1)) {
                return false;
            }
        }

        return true;
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
    public static function coreGroupFor(int $primaryGroupId, string $secondaryGroupIds = ''): int
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
    public static function tagPlacement(array $nodes): array
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

    /** One of the board's settings (`setting`), or ''. */
    public static function setting(ConnectionInterface $conn, string $p, string $name): string
    {
        static $cache = [];

        return $cache[$p.$name] ??= (string) ($conn->getSchemaBuilder()->hasTable($p.'setting')
            ? $conn->table($p.'setting')->where('varname', $name)->value('value') : '');
    }

    /**
     * Groups (vBulletin 3 to 6): `usergroup`, a member's primary group in
     * `user.usergroupid` and the rest in `membergroupids`. The system groups
     * have the same ids everywhere: 1 guests, 2 registered, 3 awaiting email
     * confirmation, 4 awaiting moderation, 5 super moderators, 6
     * administrators, 7 moderators, 8 banned. vBulletin 5's channel and
     * social-group roles (`systemgroupid` 9 and up) are permission sets.
     */
    public static function groups(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $roles = [1 => 'guest', 2 => 'member', 3 => 'pending', 4 => 'pending', 5 => 'mod', 6 => 'admin', 7 => 'mod', 8 => 'banned'];

        return [
            'needs' => [$p.'usergroup'],
            'groups' => fn (Ctx $ctx) => $ctx->src()->table($p.'usergroup')->orderBy('usergroupid')->get()->map(fn ($g) => [
                'id' => (string) $g->usergroupid, 'name' => (string) $g->title,
                'color' => preg_match('/color\s*[:=]\s*["\']?\s*(#[0-9a-f]{3,6})/i', (string) ($g->opentag ?? ''), $m) ? $m[1] : null,
                'role' => $roles[(int) $g->usergroupid] ?? ((int) ($g->systemgroupid ?? 0) >= 9 ? 'permissions' : null),
            ])->all(),
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::byMember($ctx, $after, $limit, fn (Ctx $c) => $c->src()->table($p.'user'), 'userid',
                fn ($u) => [$u->usergroupid, ...Groups::ids((string) ($u->membergroupids ?? ''))]),
        ];
    }

    /**
     * Profile photos and birthdays (vBulletin 3 to 6). An uploaded picture is
     * in `customavatar` — the bytes themselves, or with avatars kept as files,
     * customavatars/avatar<id>_<revision>.gif (`--avatar-path` names that
     * folder when it is elsewhere). A gallery picture is `avatar.avatarpath`.
     * `user.birthday` is "mm-dd-yyyy", the year 0000 when not given.
     */
    public static function profiles(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));

        return [
            'count' => fn () => (int) Src::connect($cfg)->table($p.'user')->count(),
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $cfg) {
                $conn = $ctx->src();
                $sb = $conn->getSchemaBuilder();
                $q = $conn->table($p.'user as u')->select('u.*');
                if ($custom = $sb->hasTable($p.'customavatar')) {
                    $q->leftJoin($p.'customavatar as ca', 'ca.userid', '=', 'u.userid')->addSelect('ca.filename as ca_filename', $conn->raw('CASE WHEN ca.userid IS NULL THEN 0 ELSE 1 END AS ca_has'));
                }
                if ($gallery = $sb->hasTable($p.'avatar')) {
                    $q->leftJoin($p.'avatar as av', 'av.avatarid', '=', 'u.avatarid')->addSelect('av.avatarpath');
                }
                $folder = ($cfg['avatar_path'] ?? '') !== '' ? ['dir' => (string) $cfg['avatar_path']] : Files::folder(self::setting($conn, $p, 'avatarpath'), 'customavatars');

                return Profiles::page($q, 'u.userid', $after, $limit, function ($u) use ($conn, $p, $custom, $gallery, $folder) {
                    $avatar = null;
                    if ($custom && (int) ($u->ca_has ?? 0)) {
                        $avatar = $folder + [
                            // The bytes, when the board keeps avatars in the database: read only for this member.
                            'bytes' => fn () => (string) $conn->table($p.'customavatar')->where('userid', $u->userid)->value('filedata'),
                            'path' => array_filter([basename((string) ($u->ca_filename ?? '')), 'avatar'.$u->userid.'_'.(int) ($u->avatarrevision ?? 0).'.gif']),
                        ];
                    } elseif ($gallery && ($path = trim((string) ($u->avatarpath ?? ''))) !== '') {
                        $avatar = ['path' => $path, 'url' => $path];
                    }

                    return ['avatar' => $avatar, 'birthday' => Profiles::date((string) ($u->birthday ?? ''), 'mdy')];
                });
            },
        ];
    }

    /** Where a vBulletin board keeps attachment files: by member id, a folder per digit. */
    public static function attachFolder(ConnectionInterface $conn, string $p): array
    {
        return Files::folder(self::setting($conn, $p, 'attachpath'), 'attachments');
    }

    public static function digits(mixed $userId): string
    {
        return implode('/', str_split((string) (int) $userId));
    }

    /**
     * Attachments (vBulletin 5/6): Attach nodes under the post, `attach`
     * naming the `filedata` whose bytes are in the database, or with files
     * kept on disk, <attachpath>/<member digits>/<filedata id>.attach. A post
     * places one with [ATTACH]<node id>[/ATTACH].
     */
    public static function attachments(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));

        $att = fn (ConnectionInterface $conn, object $a, array $folder) => $folder + [
            'id' => (string) $a->nodeid, 'name' => (string) ($a->filename ?: 'attachment.'.$a->extension), 'uid' => $a->userid,
            'bytes' => fn () => (string) $conn->table($p.'filedata')->where('filedataid', $a->filedataid)->value('filedata'),
            'path' => self::digits($a->userid).'/'.(int) $a->filedataid.'.attach',
            'url' => 'filedata/fetch?id='.$a->nodeid,
        ];
        $query = fn (ConnectionInterface $conn) => $conn->table($p.'attach as a')->leftJoin($p.'node as n', 'n.nodeid', '=', 'a.nodeid')
            ->leftJoin($p.'filedata as f', 'f.filedataid', '=', 'a.filedataid')
            ->select('a.nodeid', 'n.parentid', 'n.userid', 'a.filedataid', 'a.filename', 'f.extension');

        return ['fetch' => function (array $ids, Ctx $ctx) use ($p, $att, $query) {
            $conn = $ctx->src();
            if (! $conn->getSchemaBuilder()->hasTable($p.'attach')) {
                return [];
            }
            $folder = self::attachFolder($conn, $p);
            $out = [];
            foreach ($query($conn)->whereIn('n.parentid', $ids)->orderBy('a.nodeid')->get() as $a) {
                $out[$a->parentid][] = $att($conn, $a, $folder);
            }

            return $out;
        }, 'lookup' => function (string $ref, Ctx $ctx) use ($p, $att, $query) {
            $conn = $ctx->src();
            $a = ctype_digit($ref) && $conn->getSchemaBuilder()->hasTable($p.'attach') ? $query($conn)->where('a.nodeid', (int) $ref)->first() : null;

            return $a ? $att($conn, $a, self::attachFolder($conn, $p)) : null;
        }];
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
        $import = $textTypeIds && $channelIds;
        // What the import will write, not every Text node: blog entries,
        // visitor messages and deleted posts are nodes too.
        $starters = fn () => self::live($conn, $p, $conn->table($p.'node'))->whereIn($p.'node.contenttypeid', $textTypeIds)->whereIn($p.'node.parentid', $channelIds);
        $posts = 0;
        if ($import) {
            $q = self::live($conn, $p, $conn->table($p.'node'))->whereIn($p.'node.contenttypeid', $textTypeIds);
            $posts = $sb->hasColumn($p.'node', 'starter')
                ? (int) $q->whereIn($p.'node.starter', $starters()->select($p.'node.nodeid'))->count()
                : (int) $starters()->count() + (int) $q->whereIn($p.'node.parentid', $starters()->select($p.'node.nodeid'))->count();
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'user')->count(),
            'categories' => count($channelIds),
            'topics' => $import ? (int) $starters()->count() : 0,
            'posts' => $posts,
            'avatars' => $sb->hasTable($p.'customavatar') ? (int) $conn->table($p.'customavatar')->count() : 0,
        ]];
    }

    /**
     * Only what the board itself shows: approved, published, not deleted.
     * 🚨 A soft-deleted thread or reply keeps `approved` = 1 and was imported
     * as a visible post; vBulletin marks it with `showpublished` = 0 and the
     * deleting member's `deleteuserid`. Both columns are checked first, as
     * an older or trimmed schema may lack them.
     */
    private static function live(ConnectionInterface $conn, string $p, \Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder
    {
        $sb = $conn->getSchemaBuilder();
        $q->where($p.'node.approved', 1);
        if ($sb->hasColumn($p.'node', 'showpublished')) {
            $q->where($p.'node.showpublished', 1);
        }
        if ($sb->hasColumn($p.'node', 'deleteuserid')) {
            $q->where(fn ($w) => $w->whereNull($p.'node.deleteuserid')->orWhere($p.'node.deleteuserid', '<', 1));
        }

        return $q;
    }

    /**
     * Channels of this batch that guests and members could not view, from
     * vBulletin 5's `permission` table (nodeid, groupid, forumpermissions; a
     * channel without rows inherits its parent channel's). Two queries for
     * the whole batch.
     *
     * @param  int[]  $ids
     * @return array<int,true>
     */
    private static function restrictedChannels(ConnectionInterface $conn, string $p, array $channelTypeIds, array $ids): array
    {
        if (! $ids || ! $conn->getSchemaBuilder()->hasTable($p.'permission')) {
            return [];
        }
        $perm = [];
        foreach ($conn->table($p.'permission')->whereIn('groupid', [1, 2])->get(['nodeid', 'groupid', 'forumpermissions']) as $r) {
            $perm[(int) $r->nodeid][(int) $r->groupid] = (int) $r->forumpermissions;
        }
        $parent = $conn->table($p.'node')->whereIn('contenttypeid', $channelTypeIds)->pluck('parentid', 'nodeid')->all();
        $out = [];
        foreach ($ids as $id) {
            $chain = [];
            for ($cur = (int) $id; $cur > 0 && count($chain) < 50; $cur = (int) ($parent[$cur] ?? 0)) {
                $chain[] = $cur;
            }
            if (self::restricted($chain, $perm, [])) {
                $out[(int) $id] = true;
            }
        }

        return $out;
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
                    $restricted = self::restrictedChannels($conn, $p, $channelTypeIds, $rows->pluck('nodeid')->all());
                    // Old /forum/… links name the channel by its route prefix.
                    $prefixes = $conn->getSchemaBuilder()->hasTable($p.'routenew') && $rows->isNotEmpty()
                        ? $conn->table($p.'routenew')->where('class', 'vB5_Route_Channel')->whereIn('contentid', $rows->pluck('nodeid')->all())->get(['prefix', 'contentid'])
                        : collect();

                    $map = [];
                    $n = 0;
                    foreach ($rowsRaw as $c) {
                        $cursor = max($cursor, $c->nodeid);
                    }
                    foreach ($rowsSorted as $c) {
                        $place = $placement[$c->nodeid] ?? ['parentSrcId' => null, 'namePrefix' => ''];
                        $name = self::plain($c->title ?? '') ?: ('Channel '.$c->nodeid);
                        if ($place['namePrefix'] !== '') {
                            $name .= ' ('.$place['namePrefix'].')';
                        }
                        $parentTagId = $place['parentSrcId'] !== null
                            ? ($parentTagMap[(string) $place['parentSrcId']] ?? $map[$place['parentSrcId']] ?? null)
                            : null;
                        // Safety net: a parent tag that can't be resolved (it lands in a
                        // later batch because of an out-of-order nodeid) leaves this one a
                        // top-level tag rather than one pointing at a parent that isn't there.
                        $tagId = Dst::tag($name, Src::tagSlug($name, (int) $c->nodeid), isset($c->description) ? self::plain($c->description) : null, null, (int) ($c->displayorder ?? 0), $parentTagId, isset($restricted[$c->nodeid]));
                        $map[$c->nodeid] = $tagId;
                        if ($place['parentSrcId'] === null) {
                            // Make this batch's own primary tags resolvable as a parent
                            // for a secondary tag later in the same batch.
                            $parentTagMap[(string) $c->nodeid] = $tagId;
                        }
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);
                    $slugs = [];
                    foreach ($prefixes as $r) {
                        // 🚨 importer_map.source_id is 64 characters: a deeper path would fail the step.
                        if (isset($map[$r->contentid]) && (string) $r->prefix !== '' && strlen((string) $r->prefix) <= 64) {
                            $slugs[(string) $r->prefix] = $map[$r->contentid];
                        }
                    }
                    $ctx->mapPut('tag-slug', $slugs);

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
                    $bans = self::bans($conn, $p, $rows->pluck('userid')->all());
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->userid;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        $name = self::plain(($hasDisplayName ? ($u->displayname ?? null) : null) ?: $u->username ?? '');
                        $lastSeen = ((int) ($u->lastactivity ?? 0)) > 0 ? Src::ts($u->lastactivity) : (((int) ($u->lastvisit ?? 0)) > 0 ? Src::ts($u->lastvisit) : null);
                        [$confirmed, $suspended] = self::standing($u, $bans);
                        try {
                            $uid = Dst::user(Src::username($name !== '' ? $name : null, (int) $u->userid), $email, null, Src::ts($u->joindate ?? null), $lastSeen, self::legacy($u), $confirmed, $suspended, $name !== '' ? $name : null);
                            $map[$u->userid] = $uid;
                            // vBulletin's "Administrators"/"Super Moderators"/"Moderators" system
                            // groups map onto Flarum's built-in Admin/Mod groups; banned and
                            // unconfirmed members come across by {@see standing()} above.
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

            // Thread starters: Text (or Poll, Gallery, …) nodes whose parent is a
            // Channel. The node's own text row is the discussion's first post (#1).
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
                    $rows = self::live($conn, $p, $conn->table($p.'node'))
                        ->join($p.'text', $p.'text.nodeid', '=', $p.'node.nodeid')
                        ->whereIn($p.'node.contenttypeid', $textTypeIds)
                        ->whereIn($p.'node.parentid', $channelIds)
                        ->where($p.'node.nodeid', '>', (int) $cursor)
                        ->orderBy($p.'node.nodeid')
                        ->select($p.'node.*', $p.'text.rawtext', $p.'text.htmlstate')
                        ->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('parentid')->all()) : [];
                    $files = Attachments::batch(self::attachments($cfg), $ctx, $rows->pluck('nodeid')->all());
                    $bodies = [];
                    foreach ($rows as $node) {
                        $bodies[$node->nodeid] = self::body($node->rawtext ?? '', $node->htmlstate ?? '', $files->resolver($node->nodeid)) ?: '<p></p>';
                    }
                    $bodies = $files->finish($bodies);
                    Dst::prepare($ctx, $bodies);
                    $map = $posts = [];
                    $n = 0;
                    foreach ($rows as $node) {
                        $cursor = $node->nodeid;
                        $title = self::plain($node->title ?? '') ?: 'Untitled';
                        $created = Src::ts($node->publishdate ?? $node->created ?? null);
                        $uid = $userMap[(string) $node->userid] ?? null;
                        // `open` = 0 is a closed thread.
                        $did = Dst::discussion($title, $uid, $created, (bool) ($node->sticky ?? false), (int) ($node->open ?? 1) === 0);
                        $map[$node->nodeid] = $did;
                        if ($hasTags && isset($tagMap[(string) $node->parentid])) {
                            Dst::attachTag($did, $tagMap[(string) $node->parentid]);
                        }
                        // The starter's own nodeid is what an old /node/123 or ?p= link names.
                        $posts[$node->nodeid] = Dst::post($did, 1, $uid, $bodies[$node->nodeid], $created);
                        $files->link($node->nodeid, $posts[$node->nodeid]);
                        Dst::ref('post', (string) $node->nodeid, $posts[$node->nodeid]);
                        // Finalize immediately: topics with zero replies are never
                        // visited again by the posts phase, so without this their
                        // first_post_id/last_post_id/comment_count stay NULL/0 and
                        // Flarum renders them as an empty "Untitled" discussion.
                        Dst::finalizeDiscussion($did);
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);
                    $ctx->mapPut('post', $posts);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => $files->done(['topics' => $n, 'posts' => $n])];
                }
            ),

            // Replies, and comments on replies: every post node below a thread
            // starter. 🚨 A comment's parent is the reply it answers, not the
            // thread, so they were all dropped; `starter` names the thread for
            // both. Walked by (thread, node) like Phases::posts, so each
            // discussion is numbered in one run and finalised once.
            new Phase(
                'posts',
                'posts',
                fn () => 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $cfg) {
                    $conn = $ctx->src();
                    [$channelTypeIds, $textTypeIds] = self::typeIds($conn, $p);
                    if (! $textTypeIds) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    // A schema without `starter` (none seen in the wild) still has direct replies by parent.
                    $hasStarter = $conn->getSchemaBuilder()->hasColumn($p.'node', 'starter');
                    $tid = $hasStarter ? 'starter' : 'parentid';
                    $cur = is_array($cursor) && isset($cursor['tid']) ? $cursor : ['tid' => 0, 'nid' => 0, 'carry' => null];
                    $carry = $cur['carry'] ?? null;

                    $q = self::live($conn, $p, $conn->table($p.'node'))
                        ->join($p.'text', $p.'text.nodeid', '=', $p.'node.nodeid')
                        ->whereIn($p.'node.contenttypeid', $textTypeIds);
                    if ($hasStarter) {
                        $q->where($p.'node.starter', '>', 0)->whereColumn($p.'node.nodeid', '!=', $p.'node.starter');
                    } elseif ($channelIds = self::channelNodeIds($conn, $p, $channelTypeIds)) {
                        $q->whereNotIn($p.'node.parentid', $channelIds);
                    }
                    $rows = $q->where(fn ($w) => $w->where($p.'node.'.$tid, '>', (int) $cur['tid'])
                        ->orWhere(fn ($w2) => $w2->where($p.'node.'.$tid, (int) $cur['tid'])->where($p.'node.nodeid', '>', (int) $cur['nid'])))
                        ->orderBy($p.'node.'.$tid)->orderBy($p.'node.nodeid')
                        ->select($p.'node.*', $p.'text.rawtext', $p.'text.htmlstate')
                        ->limit($limit)->get();

                    $topicMap = $ctx->mapGet('topic', $rows->pluck($tid)->all());
                    $userMap = $ctx->mapGet('user', $rows->pluck('userid')->all());
                    $files = Attachments::batch(self::attachments($cfg), $ctx, $rows->filter(fn ($r) => isset($topicMap[(string) $r->$tid]))->pluck('nodeid')->all());
                    $bodies = [];
                    foreach ($rows as $node) {
                        if (isset($topicMap[(string) $node->$tid])) {
                            $bodies[$node->nodeid] = self::body($node->rawtext ?? '', $node->htmlstate ?? '', $files->resolver($node->nodeid)) ?: '<p></p>';
                        }
                    }
                    $bodies = $files->finish($bodies);
                    Dst::prepare($ctx, $bodies);
                    // Where each discussion's numbering stands, in one query for the
                    // batch (the starter is #1).
                    $fresh = array_values(array_diff(array_unique(array_values($topicMap)), [(int) ($carry['did'] ?? 0)]));
                    $top = $fresh ? Dst::db()->table('posts')->whereIn('discussion_id', $fresh)->groupBy('discussion_id')
                        ->selectRaw('discussion_id, MAX(number) AS n')->pluck('n', 'discussion_id')->all() : [];
                    $n = 0;
                    $posts = [];
                    foreach ($rows as $node) {
                        $cur['tid'] = (int) $node->$tid;
                        $cur['nid'] = (int) $node->nodeid;
                        $did = $topicMap[(string) $node->$tid] ?? null;
                        if (! $did) {
                            continue; // in a thread we didn't import
                        }
                        if (! $carry || (int) $carry['did'] !== (int) $did) {
                            if ($carry && ! empty($carry['did'])) {
                                Dst::finalizeDiscussion((int) $carry['did']);
                            }
                            $carry = ['did' => (int) $did, 'num' => (int) ($top[$did] ?? 0)];
                        }
                        $created = Src::ts($node->publishdate ?? $node->created ?? null);
                        try {
                            $posts[$node->nodeid] = Dst::post($did, ++$carry['num'], $userMap[(string) $node->userid] ?? null, $bodies[$node->nodeid], $created);
                            $files->link($node->nodeid, $posts[$node->nodeid]);
                            Dst::ref('post', (string) $node->nodeid, $posts[$node->nodeid]);
                            $n++;
                        } catch (\Throwable) {
                            $carry['num']--;
                        }
                    }
                    $ctx->mapPut('post', $posts);

                    $done = count($rows) < $limit;
                    if ($done && $carry && ! empty($carry['did'])) {
                        Dst::finalizeDiscussion((int) $carry['did']);
                    }
                    $cur['carry'] = $done ? null : $carry;

                    return ['cursor' => $cur, 'processed' => count($rows), 'done' => $done, 'summary' => $files->done(['posts' => $n])];
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
