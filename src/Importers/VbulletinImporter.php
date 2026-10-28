<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * vBulletin 3.x / 4.x → Flarum.
 *   forum → tags · user → users · thread → discussions · post → posts
 * vB hashes are md5-based (not portable) so members reset on first login.
 * Supports a table prefix; when a `node` table is present (vB5/6) we hand off
 * to Vbulletin5Importer so one wizard option covers both generations.
 */
class VbulletinImporter
{
    private static function isNodeSchema(array $cfg): bool
    {
        $conn = Src::connect($cfg);
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $sb = $conn->getSchemaBuilder();

        return $sb->hasTable($p.'node') && $sb->hasTable($p.'contenttype') && ! $sb->hasTable($p.'thread');
    }

    /**
     * Threads, without moved-thread redirects: 🚨 a thread moved to another
     * forum leaves a stub behind (`open` = 10, no posts of its own, `pollid`
     * pointing at the real thread), which became an empty discussion.
     */
    private static function threads(ConnectionInterface $conn, string $p): Builder
    {
        return $conn->table($p.'thread')->where('open', '!=', 10);
    }

    /**
     * Forums neither guests nor members could view ({@see Vbulletin5Importer::restricted()}):
     * `forumpermission` (forumid, usergroupid, forumpermissions) overrides a
     * group's default (`usergroup.forumpermissions`) for a forum and every
     * forum below it without rows of its own. One query each, for the whole tree.
     *
     * @param  Collection<int|string,\stdClass>  $forums  keyed by forumid
     * @return array<int,true>
     */
    private static function restrictedForums(ConnectionInterface $conn, string $p, Collection $forums): array
    {
        $sb = $conn->getSchemaBuilder();
        if (! $sb->hasTable($p.'forumpermission')) {
            return [];
        }
        $perm = [];
        foreach ($conn->table($p.'forumpermission')->whereIn('usergroupid', [1, 2])->get(['forumid', 'usergroupid', 'forumpermissions']) as $r) {
            $perm[(int) $r->forumid][(int) $r->usergroupid] = (int) $r->forumpermissions;
        }
        $defaults = $sb->hasTable($p.'usergroup') && $sb->hasColumn($p.'usergroup', 'forumpermissions')
            ? $conn->table($p.'usergroup')->whereIn('usergroupid', [1, 2])->pluck('forumpermissions', 'usergroupid')->map(fn ($v) => (int) $v)->all()
            : [];
        $out = [];
        foreach ($forums as $f) {
            // parentlist is "self,parent,…,-1"; without it, walk parentid.
            $chain = array_values(array_filter(array_map('intval', explode(',', (string) ($f->parentlist ?? ''))), fn ($id) => $id > 0));
            if (! $chain) {
                for ($cur = (int) $f->forumid; $cur > 0 && count($chain) < 50; $cur = (int) ($forums[$cur]->parentid ?? -1)) {
                    $chain[] = $cur;
                }
            }
            if (Vbulletin5Importer::restricted($chain, $perm, $defaults)) {
                $out[(int) $f->forumid] = true;
            }
        }

        return $out;
    }

    public static function test(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::test($cfg);
        }
        $conn = Src::connect($cfg);
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $sb = $conn->getSchemaBuilder();
        foreach (['user', 'thread', 'post', 'forum'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.vbulletin', ['table' => $p.$req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'user')->count(),
            'categories' => (int) $conn->table($p.'forum')->count(),
            'topics' => (int) self::threads($conn, $p)->count(),
            'posts' => (int) $conn->table($p.'post')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::phases($cfg);
        }
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase(
                'tags',
                'categories',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'forum')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    /*
                     * 🚨 Every forum used to become a top-level tag: sub-forums lost
                     * their parent, and a category (a forum that cannot hold threads)
                     * became an empty tag of its own. The tree is placed as vBulletin
                     * 5's is, onto Flarum's two levels. It is small, so it is read
                     * and written in one step: a parent then always exists before
                     * its children, whatever their ids. A sync starts past the last
                     * forum it has and adds only new ones.
                     */
                    $conn = $ctx->src();
                    $all = $conn->table($p.'forum')->orderBy('forumid')->get()->keyBy('forumid');
                    $nodes = [(object) ['nodeid' => -1, 'parentid' => -2, 'title' => '']]; // a root above the top-level forums
                    $parents = [];
                    foreach ($all as $f) {
                        $parent = (int) ($f->parentid ?? -1);
                        $parent = isset($all[$parent]) ? $parent : -1;
                        $parents[$parent] = true;
                        $nodes[] = (object) ['nodeid' => (int) $f->forumid, 'parentid' => $parent, 'title' => Vbulletin5Importer::plain($f->title)];
                    }
                    $placement = Vbulletin5Importer::tagPlacement($nodes);
                    $restricted = self::restrictedForums($conn, $p, $all);
                    // A category (options bit 4, "can contain threads", off) with no
                    // forum under it is an empty tag — unless it held threads before
                    // it was made a category. One query for those few.
                    $empty = $all->filter(fn ($f) => (int) $f->forumid > (int) $cursor && ! isset($parents[(int) $f->forumid]) && isset($f->options) && ! ((int) $f->options & 4))->keys()->all();
                    if ($empty) {
                        $empty = array_diff($empty, $conn->table($p.'thread')->whereIn('forumid', $empty)->distinct()->pluck('forumid')->all());
                    }
                    $todo = $all->filter(fn ($f) => (int) $f->forumid > (int) $cursor && ! in_array($f->forumid, $empty))
                        ->sortBy(fn ($f) => [($placement[(int) $f->forumid]['parentSrcId'] ?? null) === null ? 0 : 1, (int) $f->forumid]);
                    $tagIds = $ctx->mapGet('tag', $todo->map(fn ($f) => $placement[(int) $f->forumid]['parentSrcId'] ?? null)->filter()->unique()->all());
                    $map = [];
                    foreach ($todo as $f) {
                        $place = $placement[(int) $f->forumid] ?? ['parentSrcId' => null, 'namePrefix' => ''];
                        $name = Vbulletin5Importer::plain($f->title) ?: 'Forum';
                        if ($place['namePrefix'] !== '') {
                            $name .= ' ('.$place['namePrefix'].')';
                        }
                        $parentTag = $place['parentSrcId'] !== null ? ($tagIds[(string) $place['parentSrcId']] ?? null) : null;
                        $tagIds[(string) $f->forumid] = Dst::tag($name, Src::tagSlug($name, (int) $f->forumid), isset($f->description) ? Vbulletin5Importer::plain($f->description) : null, null, (int) ($f->displayorder ?? 0), $parentTag, isset($restricted[(int) $f->forumid]));
                        $map[$f->forumid] = $tagIds[(string) $f->forumid];
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => max((int) $cursor, (int) $all->keys()->max()), 'processed' => $all->count(), 'done' => true, 'summary' => ['categories' => count($map)]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'user')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $rows = $ctx->src()->table($p.'user')->where('userid', '>', (int) $cursor)->orderBy('userid')->limit($limit)->get();
                    $bans = Vbulletin5Importer::bans($ctx->src(), $p, $rows->pluck('userid')->all());
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->userid;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        $name = Vbulletin5Importer::plain($u->username ?? '');
                        [$confirmed, $suspended] = Vbulletin5Importer::standing($u, $bans);
                        try {
                            // md5(md5(password) . salt), checked at the member's first sign-in.
                            $map[$u->userid] = $uid = Dst::user(Src::username($name, (int) $u->userid), $email, null, Src::ts($u->joindate ?? null), null, Vbulletin5Importer::legacy($u), $confirmed, $suspended, $name !== '' ? $name : null);
                            // 🚨 Administrators and moderators arrived as plain members.
                            Dst::assignCoreGroup($uid, Vbulletin5Importer::coreGroupFor((int) ($u->usergroupid ?? 0), (string) ($u->membergroupids ?? '')));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            new Phase(
                'topics',
                'topics',
                fn () => (int) self::threads(Src::connect($cfg), $p)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $rows = self::threads($ctx->src(), $p)->where('threadid', '>', (int) $cursor)->orderBy('threadid')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('postuserid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('forumid')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('threadid'));
                    foreach ($rows as $t) {
                        $cursor = $t->threadid;
                        if (isset($known[(string) $t->threadid])) {
                            continue;
                        }
                        if ((int) ($t->visible ?? 1) !== 1) {
                            continue;
                        }
                        // `open` = 0 is a closed thread.
                        $did = Dst::discussion(Vbulletin5Importer::plain($t->title) ?: 'Untitled', $userMap[(string) $t->postuserid] ?? null, Src::ts($t->dateline ?? null), (bool) ($t->sticky ?? false), (int) ($t->open ?? 1) === 0);
                        $map[$t->threadid] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->forumid])) {
                            Dst::attachTag($did, $tagMap[(string) $t->forumid]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                $p.'post',
                'threadid',
                'postid',
                fn () => (int) Src::connect($cfg)->table($p.'post')->count(),
                fn ($post, ?Attachments $files = null) => [
                    'tid' => (int) $post->threadid, 'pid' => (int) $post->postid, 'uid' => $post->userid,
                    'html' => Bbcode::toHtml($post->pagetext ?? '', array_filter(['attachment' => $files?->resolver($post->postid)])), 'at' => Src::ts($post->dateline ?? null),
                    'ok' => (int) ($post->visible ?? 1) === 1,
                ],
                files: self::attachments($cfg),
            ),
        ], Phases::tail());
    }

    /** The same tables on every generation: {@see Vbulletin5Importer::groups()}. */
    public static function groups(array $cfg): array
    {
        return Vbulletin5Importer::groups($cfg);
    }

    /** {@see Vbulletin5Importer::profiles()} */
    public static function profiles(array $cfg): array
    {
        return Vbulletin5Importer::profiles($cfg);
    }

    /**
     * Attachments. vBulletin 3 keeps the file on `attachment` itself
     * (`filedata`, or on disk as <attachpath>/<member digits>/<attachment
     * id>.attach); vBulletin 4 puts it in `filedata`, shared by every
     * attachment of the same file (<filedata id>.attach on disk), with
     * `attachment.contentid` the post. Either way a post places one with
     * [ATTACH]<attachment id>[/ATTACH], and attachment.php serves it.
     */
    public static function attachments(array $cfg): array
    {
        $p = trim((string) ($cfg['prefix'] ?? ''));

        return ['fetch' => function (array $ids, Ctx $ctx) use ($p) {
            $conn = $ctx->src();
            $sb = $conn->getSchemaBuilder();
            if (! $sb->hasTable($p.'attachment')) {
                return [];
            }
            $folder = Vbulletin5Importer::attachFolder($conn, $p);
            $v4 = $sb->hasColumn($p.'attachment', 'filedataid');
            $q = $conn->table($p.'attachment as a');
            if ($v4) {
                $post = $sb->hasTable($p.'contenttype') ? $conn->table($p.'contenttype')->where('class', 'Post')->value('contenttypeid') : null;
                $q->whereIn('a.contentid', $ids)->when($post, fn ($w) => $w->where('a.contenttypeid', $post))
                    ->select('a.attachmentid', 'a.contentid as postid', 'a.userid', 'a.filename', 'a.filedataid');
            } else {
                $q->whereIn('a.postid', $ids)->select('a.attachmentid', 'a.postid', 'a.userid', 'a.filename');
            }
            // One held for moderation was never shown under the post.
            $q->when($sb->hasColumn($p.'attachment', 'state'), fn ($w) => $w->where('a.state', 'visible'))
                ->when(! $v4 && $sb->hasColumn($p.'attachment', 'visible'), fn ($w) => $w->where('a.visible', 1));
            $out = [];
            foreach ($q->orderBy('a.attachmentid')->get() as $a) {
                [$table, $key, $id] = $v4 ? [$p.'filedata', 'filedataid', $a->filedataid] : [$p.'attachment', 'attachmentid', $a->attachmentid];
                $out[$a->postid][] = $folder + [
                    'id' => (string) $a->attachmentid, 'name' => (string) $a->filename, 'uid' => $a->userid,
                    'bytes' => fn () => (string) $conn->table($table)->where($key, $id)->value('filedata'),
                    'path' => Vbulletin5Importer::digits($a->userid).'/'.(int) $id.'.attach',
                    'url' => 'attachment.php?attachmentid='.$a->attachmentid,
                ];
            }

            return $out;
        }, 'inline' => '#/attachment\.php$#'];
    }
    /**
     * vBulletin 3/4: `pmtext` holds each message once (`fromuserid`, `title`,
     * `message`, `touserarray` — serialized [userid => name], or from 3.7
     * ['cc' => [...], 'bcc' => [...]]) and `pm` one row per member's copy
     * (`userid`, `folderid` -1 = sent items, `messageread` 0 while unread).
     * Deleting removes the member's `pm` row, so a text nobody holds a copy of
     * any more was deleted by everyone. Delegates to vBulletin 5 on its schema.
     */
    public static function privateMessages(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::privateMessages($cfg);
        }
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'pmtext') && $conn->getSchemaBuilder()->hasTable($p.'pm');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'pmtext')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'pmtext')->where('pmtextid', '>', $after)->orderBy('pmtextid')->limit($limit)->get();
                $copies = [];
                foreach ($rows->isEmpty() ? [] : $conn->table($p.'pm')->whereIn('pmtextid', $rows->pluck('pmtextid')->all())->get() as $c) {
                    $copies[$c->pmtextid][] = $c;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->pmtextid;
                    $mine = $copies[$m->pmtextid] ?? [];
                    if (! $mine) {
                        continue;
                    }
                    $list = @unserialize((string) ($m->touserarray ?? ''), ['allowed_classes' => false]);
                    $to = $bcc = [];
                    foreach (is_array($list) ? $list : [] as $k => $v) {
                        if ($k === 'cc' || $k === 'bcc') {
                            $ids = is_array($v) ? array_keys($v) : [];
                            if ($k === 'cc') {
                                $to = array_merge($to, $ids);
                            } else {
                                $bcc = array_merge($bcc, $ids);
                            }
                        } else {
                            $to[] = $k;
                        }
                    }
                    $people = array_merge([$m->fromuserid], $to);
                    $out[] = [
                        'id' => (string) $m->pmtextid,
                        'conv' => PrivateMessages::conv('', $people),
                        'aliases' => array_map(fn ($c) => (string) $c->pmid, $mine), // each member's copy: private.php?pmid=
                        'title' => (string) ($m->title ?? ''),
                        'uid' => $m->fromuserid,
                        'people' => $people,
                        'bcc' => $bcc,
                        'keep' => array_map(fn ($c) => $c->userid, $mine),
                        'unread' => array_map(fn ($c) => $c->userid, array_filter($mine, fn ($c) => (int) ($c->folderid ?? 0) !== -1 && ! (int) ($c->messageread ?? 1))),
                        'html' => Bbcode::toHtml($m->message ?? ''),
                        'at' => Src::ts($m->dateline ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * `poll` keeps its options and per-option totals as "|||"-separated lists
     * (numbered 1… in `pollvote.voteoption`), and a thread points at its poll
     * with `thread.pollid`. 🚨 A moved-thread redirect (open = 10) reuses
     * `pollid` for the thread it points to, so it never claims a poll.
     */
    public static function polls(array $cfg): array
    {
        if (self::isNodeSchema($cfg)) {
            return Vbulletin5Importer::polls($cfg);
        }
        $p = trim((string) ($cfg['prefix'] ?? ''));
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'poll') && $conn->getSchemaBuilder()->hasTable($p.'pollvote');

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
                [$rows, $cursor, $done, $n] = Polls::page($conn->table($p.'poll'), 'pollid', $after, $limit, $ctx);
                $ids = $rows->pluck('pollid')->all();
                $threads = $ids ? $conn->table($p.'thread')->whereIn('pollid', $ids)->where('open', '!=', 10)->get(['threadid', 'pollid', 'postuserid'])->keyBy('pollid') : collect();
                $votes = [];
                foreach ($ids ? $conn->table($p.'pollvote')->whereIn('pollid', $ids)->get(['pollid', 'userid', 'voteoption', 'votedate']) : [] as $v) {
                    $votes[$v->pollid][] = [$v->userid, (string) $v->voteoption, Polls::at($v->votedate)];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $thread = $threads->get($poll->pollid);
                    $totals = explode('|||', (string) ($poll->votes ?? ''));
                    $opts = [];
                    foreach (explode('|||', (string) $poll->options) as $i => $text) {
                        $opts[(string) ($i + 1)] = ['text' => Polls::plain(Bbcode::toHtml($text)), 'count' => (int) ($totals[$i] ?? 0)];
                    }
                    $at = Src::ts($poll->dateline ?? null);
                    $out[] = [
                        'id' => (string) $poll->pollid,
                        'topic' => $thread ? (string) $thread->threadid : null,
                        'question' => Polls::plain(Bbcode::toHtml((string) $poll->question)),
                        'uid' => $thread->postuserid ?? null,
                        'at' => $at,
                        'ends' => (int) ($poll->timeout ?? 0) > 0 ? $at->copy()->addDays((int) $poll->timeout)
                            : ((int) ($poll->active ?? 1) ? null : Polls::closedAt(Polls::at($poll->lastvote ?? 0), $at)),
                        'max' => (int) ($poll->multiple ?? 0) ? 0 : 1,
                        'public' => (bool) ($poll->public ?? false),
                        'change' => false,
                        'options' => $opts,
                        'votes' => $votes[$poll->pollid] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
