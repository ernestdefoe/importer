<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

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

    /** MyBB: md5(md5(salt) . md5(password)), or bcrypt where a plugin switched it. */
    private static function legacy(object $u): ?array
    {
        $hash = (string) ($u->password ?? '');
        if ($hash === '') {
            return null;
        }

        return str_starts_with($hash, '$') ? Src::legacy($hash, str_starts_with($hash, '$2') ? 'native' : null) : ['scheme' => 'mybb', 'hash' => $hash, 'salt' => (string) ($u->salt ?? '')];
    }

    /**
     * 🚨 Moving a thread with "leave a redirect" keeps a stub behind
     * (closed = 'moved|<tid>', no posts of its own): each one became an empty
     * discussion and counted in the progress bar.
     */
    private static function threads(ConnectionInterface $conn, string $p): \Illuminate\Database\Query\Builder
    {
        return $conn->table($p.'threads')->where('visible', 1)->where('closed', 'not like', 'moved|%');
    }

    /**
     * The forums neither guests (gid 1) nor registered members (gid 2) may
     * view. A forum with no `forumpermissions` row for a group inherits its
     * parent's, up `pid`, and the group's default (visible) at the top —
     * exactly how MyBB's own forum_permissions() resolves it. One query.
     *
     * @param  \Illuminate\Support\Collection<array-key, \stdClass>  $forums  keyed by fid
     * @return array<string, true>
     */
    private static function restricted(ConnectionInterface $conn, string $p, $forums): array
    {
        if (! $conn->getSchemaBuilder()->hasTable($p.'forumpermissions')) {
            return [];
        }
        $can = [];
        foreach ($conn->table($p.'forumpermissions')->whereIn('gid', [1, 2])->get(['fid', 'gid', 'canview']) as $r) {
            $can[$r->fid][$r->gid] = (int) $r->canview === 1;
        }
        $out = [];
        foreach ($forums as $fid => $f) {
            foreach ([1, 2] as $gid) {
                for ($at = $f, $i = 0; $at && ! isset($can[$at->fid][$gid]) && $i < 50; $i++) {
                    $at = $forums[(int) ($at->pid ?? 0)] ?? null;
                }
                if (! $at || $can[$at->fid][$gid]) {
                    continue 2;
                }
            }
            $out[(string) $fid] = true;
        }

        return $out;
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'forums', 'threads', 'posts'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.mybb', ['table' => $p.$req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'users')->count(),
            'categories' => (int) $conn->table($p.'forums')->where('type', 'f')->count(),
            'topics' => (int) self::threads($conn, $p)->count(),
            'posts' => (int) $conn->table($p.'posts')->where('visible', 1)->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = self::prefix($cfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase(
                'tags',
                'categories',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'forums')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    /*
                     * 🚨 The whole tree, once per batch: sub-forums lost their
                     * parent and categories (type 'c') were dropped, so the
                     * board arrived as one flat list. Flarum nests one level, so
                     * every forum goes under its top-most ancestor — on MyBB
                     * the category it sits in. A parent may have a higher fid.
                     */
                    $conn = $ctx->src();
                    $forums = $conn->table($p.'forums')->orderBy('fid')->get()->keyBy('fid');
                    $isLink = fn ($f) => trim((string) ($f->linkto ?? '')) !== '';
                    $top = [];
                    foreach ($forums as $fid => $f) {
                        if (($f->type ?? 'f') === 'f' && ! $isLink($f)) {
                            for ($t = $f, $i = 0; ($up = $forums[(int) ($t->pid ?? 0)] ?? null) && ! $isLink($up) && $i < 50; $i++) {
                                $t = $up;
                            }
                            $top[$fid] = (int) $t->fid;
                        }
                    }
                    $parents = array_flip($top);
                    $restricted = self::restricted($conn, $p, $forums);
                    $made = [];
                    $tag = function ($f, ?int $parentId) use (&$made, $restricted) {
                        return $made[$f->fid] ??= Dst::tag($f->name ?: 'Forum', Src::tagSlug($f->name ?: 'forum', (int) $f->fid), $f->description ?? null, null, (int) ($f->disporder ?? 0), $parentId, isset($restricted[(string) $f->fid]));
                    };
                    $rows = $forums->filter(fn ($f) => $f->fid > (int) $cursor)->take($limit);
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->fid;
                        if (! isset($top[$f->fid]) && ! isset($parents[$f->fid])) {
                            continue; // a link forum, or a category with no forum in it
                        }
                        $up = $top[$f->fid] ?? $f->fid;
                        // A parent with a higher fid is made now; its own turn finds it by slug.
                        $map[$f->fid] = $tag($f, $up === $f->fid ? null : $tag($forums[$up], null));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'users')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    $rows = $conn->table($p.'users')->where('uid', '>', (int) $cursor)->orderBy('uid')->limit($limit)->get();
                    // 🚨 Banned members arrived free to post. The ban is the
                    // Banned group (7); `banned.lifted` is when it ends, 0 = never.
                    $bans = [];
                    if ($rows->isNotEmpty() && $conn->getSchemaBuilder()->hasTable($p.'banned')) {
                        foreach ($conn->table($p.'banned')->whereIn('uid', $rows->pluck('uid')->all())->get(['uid', 'lifted']) as $b) {
                            $bans[$b->uid] = (int) $b->lifted === 0 ? Carbon::create(2038, 1, 1) : Src::ts($b->lifted);
                        }
                    }
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
                            $map[$u->uid] = Dst::user(Src::username($u->username ?? null, (int) $u->uid), $email, null, Src::ts($u->regdate ?? null), null, self::legacy($u),
                                // usergroup 5 = Awaiting Activation · 7 = Banned
                                confirmed: (int) ($u->usergroup ?? 2) !== 5,
                                suspendedUntil: $bans[$u->uid] ?? ((int) ($u->usergroup ?? 2) === 7 ? Carbon::create(2038, 1, 1) : null),
                                display: $u->username ?? null);
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
                    $rows = self::threads($ctx->src(), $p)->where('tid', '>', (int) $cursor)->orderBy('tid')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('uid')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('fid')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('tid'));
                    foreach ($rows as $t) {
                        $cursor = $t->tid;
                        if (isset($known[(string) $t->tid])) {
                            continue;
                        }
                        $did = Dst::discussion($t->subject ?: 'Untitled', $userMap[(string) $t->uid] ?? null, Src::ts($t->dateline ?? null), (bool) ($t->sticky ?? false), (string) ($t->closed ?? '') === '1');
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

            ...Phases::posts(
                $p.'posts',
                'tid',
                'pid',
                fn () => (int) Src::connect($cfg)->table($p.'posts')->where('visible', 1)->count(),
                fn ($post, ?Attachments $files = null) => [
                    'tid' => (int) $post->tid, 'pid' => (int) $post->pid, 'uid' => $post->uid,
                    // MyBB's [attachment=<aid>] has no closing tag.
                    'html' => Bbcode::toHtml(preg_replace('/\[attachment=(\d+)\]/i', '[attach]$1[/attach]', (string) ($post->message ?? '')), array_filter(['size' => 'pt', 'attachment' => $files?->resolver($post->pid)])),
                    'at' => Src::ts($post->dateline ?? null), 'ok' => true,
                ],
                fn ($q) => $q->where('visible', 1),
                'edittime',
                self::attachments($cfg),
            ),
        ], Phases::tail());
    }

    /** One of the board's settings (`settings`), or ''. */
    private static function setting(ConnectionInterface $conn, string $p, string $name): string
    {
        static $cache = [];

        return $cache[$p.$name] ??= (string) (($conn->getSchemaBuilder()->hasTable($p.'settings') ? $conn->table($p.'settings')->where('name', $name)->value('value') : null) ?? '');
    }

    /**
     * Groups: `usergroups`, a member's primary group in `users.usergroup` and
     * the rest in `additionalgroups`. MyBB's own: 1 guests, 2 registered,
     * 3 super moderators, 4 administrators, 5 awaiting activation, 6
     * moderators, 7 banned; a group of the board's own that may use the admin
     * control panel (`cancp`) is an admin group, one with `issupermod` a
     * moderators' group.
     */
    public static function groups(array $cfg): array
    {
        $p = self::prefix($cfg);
        $roles = [1 => 'guest', 2 => 'member', 3 => 'mod', 4 => 'admin', 5 => 'pending', 6 => 'mod', 7 => 'banned'];

        return [
            'needs' => [$p.'usergroups'],
            'groups' => fn (Ctx $ctx) => $ctx->src()->table($p.'usergroups')->orderBy('gid')->get()->map(fn ($g) => [
                'id' => (string) $g->gid, 'name' => (string) $g->title,
                'color' => preg_match('/color\s*:\s*(#[0-9a-f]{3,6})/i', (string) ($g->namestyle ?? ''), $m) ? $m[1] : null,
                'role' => $roles[(int) $g->gid] ?? (! empty($g->cancp) ? 'admin' : (! empty($g->issupermod) ? 'mod' : null)),
            ])->all(),
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::byMember($ctx, $after, $limit, fn (Ctx $c) => $c->src()->table($p.'users'), 'uid',
                fn ($u) => [$u->usergroup, ...Groups::ids((string) ($u->additionalgroups ?? ''))]),
        ];
    }

    /**
     * Profile photos (`avatar`, by `avatartype`: an uploaded file under
     * uploads/avatars, a remote address, or Gravatar's) and birthdays
     * (`birthday` "d-m-yyyy", or "d-m-" without the year).
     */
    public static function profiles(array $cfg): array
    {
        $p = self::prefix($cfg);

        return [
            'count' => fn () => (int) Src::connect($cfg)->table($p.'users')->count(),
            'fetch' => fn (int $after, int $limit, Ctx $ctx) => Profiles::page($ctx->src()->table($p.'users'), 'uid', $after, $limit, function ($u) {
                $file = trim(explode('?', (string) ($u->avatar ?? ''))[0]);
                $avatar = $file === '' ? null : (preg_match('#^(https?:)?//#i', $file) ? ['url' => $file]
                    : ['path' => preg_replace('#^\./#', '', $file), 'url' => preg_replace('#^\./#', '', $file)]);

                return ['avatar' => $avatar, 'birthday' => Profiles::date((string) ($u->birthday ?? ''), 'dmy')];
            }),
        ];
    }

    /**
     * Attachments: `attachments` (`pid`, shown once `visible`), the file at
     * <uploadspath>/<attachname>, served by attachment.php?aid=.
     */
    public static function attachments(array $cfg): array
    {
        $p = self::prefix($cfg);

        return ['fetch' => function (array $ids, Ctx $ctx) use ($p) {
            $conn = $ctx->src();
            if (! $conn->getSchemaBuilder()->hasTable($p.'attachments')) {
                return [];
            }
            $folder = Files::folder(self::setting($conn, $p, 'uploadspath'), 'uploads');
            $out = [];
            foreach ($conn->table($p.'attachments')->whereIn('pid', $ids)->where('visible', 1)->orderBy('aid')->get() as $a) {
                $out[$a->pid][] = $folder + [
                    'id' => (string) $a->aid, 'name' => (string) $a->filename, 'uid' => $a->uid,
                    'path' => (string) $a->attachname, 'url' => 'attachment.php?aid='.$a->aid,
                ];
            }

            return $out;
        }, 'inline' => '#/attachment\.php$#'];
    }
    /**
     * `privatemessages` keeps a separate COPY per member: `uid` is the copy's
     * owner, `fromid`/`toid` the sender and recipient, `recipients` a
     * serialized ['to' => [...], 'bcc' => [...]], `folder` 1 inbox · 2 sent ·
     * 3 drafts · 4 trash, `status` 0 while unread. Copies of one message share
     * sender, time and subject; the lowest `pmid` among them stands for it.
     * A copy in the trash (or with a `deletetime`) counts as deleted by its
     * owner; drafts were never sent.
     */
    public static function privateMessages(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'privatemessages');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'privatemessages')->where('folder', '!=', 3)->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'privatemessages')->where('pmid', '>', $after)->orderBy('pmid')->limit($limit)->get();
                $sent = $rows->where('folder', '!=', 3);
                $copies = [];
                if ($sent->isNotEmpty()) {
                    foreach ($conn->table($p.'privatemessages')->where('folder', '!=', 3)
                        ->whereIn('fromid', $sent->pluck('fromid')->unique()->values()->all())
                        ->whereIn('dateline', $sent->pluck('dateline')->unique()->values()->all())
                        ->orderBy('pmid')->get() as $c) {
                        $copies[$c->fromid.'|'.$c->dateline.'|'.$c->subject][] = $c;
                    }
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->pmid;
                    $all = $copies[$m->fromid.'|'.$m->dateline.'|'.$m->subject] ?? [];
                    if ((int) $m->folder === 3 || ! $all || (int) $all[0]->pmid !== (int) $m->pmid) {
                        continue; // a draft, or a copy of a message read already
                    }
                    $to = $bcc = [];
                    foreach ($all as $c) {
                        $r = @unserialize((string) ($c->recipients ?? ''), ['allowed_classes' => false]);
                        if (is_array($r)) {
                            $to = array_merge($to, array_values((array) ($r['to'] ?? [])));
                            $bcc = array_merge($bcc, array_values((array) ($r['bcc'] ?? [])));
                        }
                        if ((int) $c->uid !== (int) $c->fromid) {
                            $to[] = $c->uid;
                        }
                    }
                    $bcc = PrivateMessages::ids($bcc);
                    $people = array_diff(PrivateMessages::ids(array_merge([$m->fromid], $to)), $bcc);
                    $kept = array_filter($all, fn ($c) => (int) $c->folder !== 4 && ! (int) ($c->deletetime ?? 0));
                    $out[] = [
                        'id' => $m->fromid.':'.$m->dateline.':'.substr(md5((string) $m->subject), 0, 12),
                        'conv' => PrivateMessages::conv('', $people),
                        'aliases' => array_map(fn ($c) => (string) $c->pmid, $all), // each member's copy: private.php?pmid=
                        'title' => (string) ($m->subject ?? ''),
                        'uid' => $m->fromid,
                        'people' => array_values($people),
                        'bcc' => $bcc,
                        'keep' => array_map(fn ($c) => $c->uid, $kept),
                        'unread' => array_map(fn ($c) => $c->uid, array_filter($all, fn ($c) => (int) ($c->status ?? 1) === 0)),
                        'html' => Bbcode::toHtml($m->message ?? '', ['size' => 'pt']),
                        'at' => Src::ts($m->dateline ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * `polls` keeps its options and per-option totals as "||~|~||"-separated
     * lists (numbered 1… in `pollvotes.voteoption`), with `tid` on the poll.
     * `timeout` is days after `dateline` (a timestamp on some boards).
     */
    public static function polls(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'polls') && $conn->getSchemaBuilder()->hasTable($p.'pollvotes');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'polls')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                [$rows, $cursor, $done, $n] = Polls::page($conn->table($p.'polls'), 'pid', $after, $limit, $ctx);
                $ids = $rows->pluck('pid')->all();
                $authors = $ids ? $conn->table($p.'threads')->whereIn('tid', $rows->pluck('tid')->all())->pluck('uid', 'tid')->all() : [];
                $votes = [];
                foreach ($ids ? $conn->table($p.'pollvotes')->whereIn('pid', $ids)->get(['pid', 'uid', 'voteoption', 'dateline']) : [] as $v) {
                    $votes[$v->pid][] = [$v->uid, (string) $v->voteoption, Polls::at($v->dateline)];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $totals = explode('||~|~||', (string) ($poll->votes ?? ''));
                    $opts = [];
                    foreach (explode('||~|~||', (string) $poll->options) as $i => $text) {
                        $opts[(string) ($i + 1)] = ['text' => Polls::plain(Bbcode::toHtml($text)), 'count' => (int) ($totals[$i] ?? 0)];
                    }
                    $at = Src::ts($poll->dateline ?? null);
                    $timeout = (int) ($poll->timeout ?? 0);
                    $out[] = [
                        'id' => (string) $poll->pid,
                        'topic' => (string) $poll->tid,
                        'question' => Polls::plain(Bbcode::toHtml((string) $poll->question)),
                        'uid' => $authors[$poll->tid] ?? null,
                        'at' => $at,
                        'ends' => $timeout > 0 ? ($timeout > 100000 ? Src::ts($timeout) : $at->copy()->addDays($timeout))
                            : ((int) ($poll->closed ?? 0) ? Polls::closedAt($at) : null),
                        'max' => (int) ($poll->multiple ?? 0) ? (int) ($poll->maxoptions ?? 0) : 1,
                        'public' => (bool) ($poll->public ?? false),
                        'change' => false,
                        'options' => $opts,
                        'votes' => $votes[$poll->pid] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
