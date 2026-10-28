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

    /** MyBB: md5(md5(salt) . md5(password)), or bcrypt where a plugin switched it. */
    private static function legacy(object $u): ?array
    {
        $hash = (string) ($u->password ?? '');
        if ($hash === '') {
            return null;
        }

        return str_starts_with($hash, '$') ? Src::legacy($hash, str_starts_with($hash, '$2') ? 'native' : null) : ['scheme' => 'mybb', 'hash' => $hash, 'salt' => (string) ($u->salt ?? '')];
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
            'topics' => (int) $conn->table($p.'threads')->where('visible', 1)->count(),
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
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'forums')->where('type', 'f')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p.'forums')->where('type', 'f')->where('fid', '>', (int) $cursor)->orderBy('fid')->limit($limit)->get();
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

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'users')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $rows = $ctx->src()->table($p.'users')->where('uid', '>', (int) $cursor)->orderBy('uid')->limit($limit)->get();
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
                            $map[$u->uid] = Dst::user(Src::username($u->username ?? null, (int) $u->uid), $email, null, Src::ts($u->regdate ?? null), null, self::legacy($u), display: $u->username ?? null);
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
                fn () => (int) Src::connect($cfg)->table($p.'threads')->where('visible', 1)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $rows = $ctx->src()->table($p.'threads')->where('visible', 1)->where('tid', '>', (int) $cursor)->orderBy('tid')->limit($limit)->get();
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

            ...Phases::posts(
                $p.'posts',
                'tid',
                'pid',
                fn () => (int) Src::connect($cfg)->table($p.'posts')->where('visible', 1)->count(),
                fn ($post) => [
                    'tid' => (int) $post->tid, 'pid' => (int) $post->pid, 'uid' => $post->uid,
                    'html' => Bbcode::toHtml($post->message ?? '', ['size' => 'pt']), 'at' => Src::ts($post->dateline ?? null), 'ok' => true,
                ],
                fn ($q) => $q->where('visible', 1),
                'edittime',
            ),
        ], Phases::tail());
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
