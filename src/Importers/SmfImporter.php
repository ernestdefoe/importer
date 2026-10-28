<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * SMF (Simple Machines Forum) 2.0 / 2.1 → Flarum.
 *   smf_boards → tags · smf_members → users · smf_topics → discussions · smf_messages → posts
 * SMF hashes mix in the username, so they aren't portable → members reset.
 * Titles/names are HTML-entity-encoded; message bodies are BBCode + <br> + entities.
 * Table prefix defaults to `smf_`.
 */
class SmfImporter
{
    private static function prefix(array $cfg): string
    {
        return trim((string) ($cfg['prefix'] ?? 'smf_')) ?: 'smf_';
    }

    private static function decode(string $s): string
    {
        return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The recycle bin board, when the bin is on: what members deleted, which
     * the old board no longer showed anywhere but there.
     */
    private static function recycleBoard(ConnectionInterface $conn, string $p): int
    {
        if (! $conn->getSchemaBuilder()->hasTable($p.'settings')) {
            return 0;
        }
        $s = $conn->table($p.'settings')->whereIn('variable', ['recycle_enable', 'recycle_board'])->pluck('value', 'variable');

        return (int) ($s['recycle_enable'] ?? 0) === 1 ? (int) ($s['recycle_board'] ?? 0) : 0;
    }

    /**
     * 🚨 `id_redirect_topic` arrived with SMF 2.1 — on a 2.0 board the topics
     * query died on the missing column and the whole phase failed. On 2.1 a
     * topic carrying it is a "moved" stub (no posts of its own worth having).
     * The recycle bin's topics stay behind with their board.
     */
    private static function topics(ConnectionInterface $conn, string $p): Builder
    {
        $q = $conn->table($p.'topics')->where('approved', 1);
        if ($conn->getSchemaBuilder()->hasColumn($p.'topics', 'id_redirect_topic')) {
            $q->where('id_redirect_topic', 0);
        }

        return ($bin = self::recycleBoard($conn, $p)) ? $q->where('id_board', '!=', $bin) : $q;
    }

    private static function body(?string $body, ?\Closure $attachment = null): string
    {
        $body = preg_replace('#<br\s*/?>#i', "\n", (string) $body) ?? (string) $body;
        // SMF 2.1 may write [attach id=12] with no closing tag.
        $body = preg_replace('#\[attach(\s[^\]]*)\](?![^\[]*\[/attach\])#i', '[attach$1][/attach]', $body) ?? $body;

        return Bbcode::toHtml($body, array_filter(['escaped' => true, 'attachment' => $attachment]));
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['members', 'boards', 'topics', 'messages'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.smf', ['table' => $p.$req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'members')->count(),
            'categories' => (int) $conn->table($p.'boards')->count(),
            'topics' => (int) self::topics($conn, $p)->count(),
            'posts' => (int) $conn->table($p.'messages')->where('approved', 1)->count(),
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
                'boards',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'boards')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    /*
                     * 🚨 The whole tree, once per batch: child boards lost their
                     * parent. Flarum nests one level, so a board goes under its
                     * top-most ancestor (a parent may have a higher id).
                     * `member_groups` lists who may see a board (-1 guests,
                     * 0 regular members); a board open to neither is staff-only.
                     */
                    $conn = $ctx->src();
                    $boards = $conn->table($p.'boards')->orderBy('id_board')->get()->keyBy('id_board');
                    $bin = self::recycleBoard($conn, $p);
                    $skip = fn ($b) => trim((string) ($b->redirect ?? '')) !== '' || (int) $b->id_board === $bin;
                    $made = [];
                    $tag = function ($b, ?int $parentId) use (&$made) {
                        $name = self::decode($b->name ?: 'Board');
                        $groups = array_map('trim', explode(',', (string) ($b->member_groups ?? '-1,0')));

                        return $made[$b->id_board] ??= Dst::tag($name, Src::tagSlug($name, (int) $b->id_board), self::decode((string) ($b->description ?? '')) ?: null, null, (int) ($b->board_order ?? 0), $parentId, ! array_intersect(['-1', '0'], $groups));
                    };
                    $rows = $boards->filter(fn ($b) => $b->id_board > (int) $cursor)->take($limit);
                    $map = [];
                    $n = 0;
                    foreach ($rows as $b) {
                        $cursor = $b->id_board;
                        if ($skip($b)) {
                            continue; // a redirect board, or the recycle bin
                        }
                        for ($t = $b, $i = 0; ($up = $boards[(int) ($t->id_parent ?? 0)] ?? null) && ! $skip($up) && $i < 50; $i++) {
                            $t = $up;
                        }
                        $map[$b->id_board] = $tag($b, $t === $b ? null : $tag($t, null));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'members')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    $rows = $conn->table($p.'members')->where('id_member', '>', (int) $cursor)->orderBy('id_member')->limit($limit)->get();
                    // 🚨 Banned members arrived free to post. A ban naming the
                    // member is a `ban_items` row of a `ban_groups` group whose
                    // expire_time (NULL = never) ends it; is_activated 10+ is a
                    // member banned outright, with or without such a row.
                    $bans = [];
                    if ($rows->isNotEmpty() && $conn->getSchemaBuilder()->hasTable($p.'ban_items') && $conn->getSchemaBuilder()->hasTable($p.'ban_groups')) {
                        foreach ($conn->table($p.'ban_items as i')->join($p.'ban_groups as g', 'g.id_ban_group', '=', 'i.id_ban_group')
                            ->whereIn('i.id_member', $rows->pluck('id_member')->all())->get(['i.id_member', 'g.expire_time']) as $b) {
                            $end = (int) $b->expire_time === 0 ? Carbon::create(2038, 1, 1) : Src::ts($b->expire_time);
                            $bans[$b->id_member] = isset($bans[$b->id_member]) && $bans[$b->id_member]->gt($end) ? $bans[$b->id_member] : $end;
                        }
                    }
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->id_member;
                        $email = trim((string) ($u->email_address ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }
                        $name = self::decode(trim((string) ($u->real_name ?? '')) ?: (string) ($u->member_name ?? ''));
                        // is_activated: 1 active · 0 never activated · 2 new email unconfirmed · 3 awaiting approval · 4 asked to be deleted · 5 COPPA; +10 = banned
                        $act = (int) ($u->is_activated ?? 1);

                        try {
                            $map[$u->id_member] = Dst::user(Src::username($name !== '' ? $name : null, (int) $u->id_member), $email, null, Src::ts($u->date_registered ?? null), null, ($u->passwd ?? '') !== '' ? ['scheme' => 'smf', 'hash' => (string) $u->passwd, 'salt' => (string) ($u->member_name ?? '')] : null,
                                confirmed: in_array($act % 10, [1, 4], true),
                                suspendedUntil: $bans[$u->id_member] ?? ($act >= 10 ? Carbon::create(2038, 1, 1) : null),
                                display: $name !== '' ? $name : null);
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            // SMF topics carry no title — it lives on the first message.
            new Phase(
                'topics',
                'topics',
                fn () => (int) self::topics(Src::connect($cfg), $p)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $conn = $ctx->src();
                    $rows = self::topics($conn, $p)->where('id_topic', '>', (int) $cursor)->orderBy('id_topic')->limit($limit)->get();
                    $firstMsgIds = $rows->pluck('id_first_msg')->filter()->all();
                    $firstMsg = [];
                    if ($firstMsgIds) {
                        foreach ($conn->table($p.'messages')->whereIn('id_msg', $firstMsgIds)->get(['id_msg', 'subject', 'poster_time']) as $m) {
                            $firstMsg[(string) $m->id_msg] = $m;
                        }
                    }
                    $userMap = $ctx->mapGet('user', $rows->pluck('id_member_started')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('id_board')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('id_topic'));
                    foreach ($rows as $t) {
                        $cursor = $t->id_topic;
                        if (isset($known[(string) $t->id_topic])) {
                            continue;
                        }
                        $fm = $firstMsg[(string) $t->id_first_msg] ?? null;
                        $title = self::decode((string) ($fm->subject ?? '')) ?: 'Untitled';
                        $did = Dst::discussion($title, $userMap[(string) $t->id_member_started] ?? null, Src::ts($fm->poster_time ?? null), (bool) ($t->is_sticky ?? false), (bool) ($t->locked ?? false));
                        $map[$t->id_topic] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->id_board])) {
                            Dst::attachTag($did, $tagMap[(string) $t->id_board]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                $p.'messages',
                'id_topic',
                'id_msg',
                fn () => (int) Src::connect($cfg)->table($p.'messages')->where('approved', 1)->count(),
                fn ($m, ?Attachments $files = null) => [
                    'tid' => (int) $m->id_topic, 'pid' => (int) $m->id_msg, 'uid' => $m->id_member,
                    'html' => self::body($m->body ?? '', $files?->resolver($m->id_msg)), 'at' => Src::ts($m->poster_time ?? null), 'ok' => true,
                ],
                fn ($q) => $q->where('approved', 1),
                'modified_time',
                self::attachments($cfg),
            ),
        ], Phases::tail());
    }

    /** One of the board's settings (`settings`), or ''. */
    private static function setting(ConnectionInterface $conn, string $p, string $name): string
    {
        static $cache = [];

        return $cache[$p.$name] ??= (string) (($conn->getSchemaBuilder()->hasTable($p.'settings') ? $conn->table($p.'settings')->where('variable', $name)->value('value') : null) ?? '');
    }

    /**
     * The attachment folders by `id_folder`: `attachmentUploadDir` is one
     * path, or with several folders JSON (2.1) or a serialized array (2.0).
     *
     * @return array<int, array{root?:string,dir?:string}>
     */
    private static function attachFolders(ConnectionInterface $conn, string $p): array
    {
        $raw = self::setting($conn, $p, 'attachmentUploadDir');
        $dirs = json_decode($raw, true);
        if (! is_array($dirs)) {
            $dirs = @unserialize($raw, ['allowed_classes' => false]);
        }
        $out = [];
        foreach (is_array($dirs) ? $dirs : [1 => $raw] as $id => $dir) {
            $out[(int) $id] = Files::folder(is_string($dir) ? $dir : '', 'attachments');
        }

        return $out;
    }

    /**
     * The file of one attachment row: <id>_<hash>.dat (2.1), <id>_<hash>
     * (2.0), or 1.x's <id>_<name><md5 of name>, in its folder.
     */
    private static function attachFile(object $a, array $folders): array
    {
        $clean = (string) preg_replace(['/\s/', '/[^\w_\.\-]/'], ['_', ''], (string) $a->filename);

        return ($folders[(int) ($a->id_folder ?? 1)] ?? reset($folders) ?: ['root' => 'attachments']) + [
            'path' => array_filter([($a->file_hash ?? '') !== '' ? $a->id_attach.'_'.$a->file_hash.'.dat' : null, ($a->file_hash ?? '') !== '' ? $a->id_attach.'_'.$a->file_hash : null, $a->id_attach.'_'.str_replace('.', '_', $clean).md5($clean)]),
        ];
    }

    /**
     * Groups: `membergroups`, a member's primary group in `id_group` (0 =
     * none) and the rest in `additional_groups`. 1 Administrator and 2 Global
     * Moderator are SMF's own; 3 Moderator is filled by SMF from each board's
     * moderators, and a group with `min_posts` of 0 or more is a post-count
     * rank SMF moves members through itself.
     */
    public static function groups(array $cfg): array
    {
        $p = self::prefix($cfg);

        return [
            'needs' => [$p.'membergroups'],
            'groups' => fn (Ctx $ctx) => $ctx->src()->table($p.'membergroups')->orderBy('id_group')->get()->map(fn ($g) => [
                'id' => (string) $g->id_group, 'name' => self::decode((string) $g->group_name), 'color' => $g->online_color ?? null,
                'role' => match (true) {
                    (int) $g->id_group === 1 => 'admin',
                    (int) $g->id_group === 2 => 'mod',
                    (int) $g->id_group === 3 => 'automatic',
                    (int) ($g->min_posts ?? -1) >= 0 => 'ranks',
                    default => null,
                },
            ])->all(),
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::byMember($ctx, $after, $limit, fn (Ctx $c) => $c->src()->table($p.'members'), 'id_member',
                fn ($u) => [$u->id_group ?? 0, ...Groups::ids((string) ($u->additional_groups ?? ''))]),
        ];
    }

    /**
     * Profile photos: an uploaded one is an `attachments` row of the member's
     * with no message (in the custom avatar folder when `attachment_type` is
     * 1, else among the attachments); otherwise `avatar` is an address or a
     * picture from the gallery. Birthdays: `birthdate`, the year 0001/0004/
     * 1004 when not given (and 0001-01-01 not set at all).
     */
    public static function profiles(array $cfg): array
    {
        $p = self::prefix($cfg);

        return [
            'count' => fn () => (int) Src::connect($cfg)->table($p.'members')->count(),
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p) {
                $conn = $ctx->src();
                $page = Profiles::page($conn->table($p.'members'), 'id_member', $after, $limit, fn ($u) => [
                    'avatar' => ($a = trim((string) ($u->avatar ?? ''))) === '' ? null
                        : (preg_match('#^(https?:)?//#i', $a) ? ['url' => $a] : Files::folder(self::setting($conn, $p, 'avatar_directory'), 'avatars') + ['path' => $a, 'url' => rtrim(self::setting($conn, $p, 'avatar_url'), '/').'/'.$a]),
                    'birthday' => ($b = Profiles::date((string) ($u->birthdate ?? ''))) && ! (($b[0] ?? 0) < 1900 && $b[1] === 1 && $b[2] === 1) ? $b : null,
                ]);
                $ids = array_column($page['profiles'], 'uid');
                if ($ids && $conn->getSchemaBuilder()->hasTable($p.'attachments')) {
                    $folders = self::attachFolders($conn, $p);
                    $custom = Files::folder(self::setting($conn, $p, 'custom_avatar_dir'), 'custom_avatar');
                    $uploads = [];
                    foreach ($conn->table($p.'attachments')->whereIn('id_member', $ids)->where('id_msg', 0)->get() as $a) {
                        $uploads[$a->id_member] = ((int) ($a->attachment_type ?? 0) === 1 ? $custom + ['path' => (string) $a->filename] : self::attachFile($a, $folders))
                            + ['url' => 'index.php?action=dlattach;attach='.$a->id_attach.';type=avatar'];
                    }
                    foreach ($page['profiles'] as &$prof) {
                        $prof['avatar'] = $uploads[$prof['uid']] ?? $prof['avatar'];
                    }
                    unset($prof);
                }

                return $page;
            },
        ];
    }

    /**
     * Attachments: `attachments` rows of the message (`attachment_type` 3
     * is a thumbnail of another), once approved, served by
     * index.php?action=dlattach. SMF 2.1 places one with [attach id=n].
     */
    public static function attachments(array $cfg): array
    {
        $p = self::prefix($cfg);

        return ['fetch' => function (array $ids, Ctx $ctx) use ($p) {
            $conn = $ctx->src();
            if (! $conn->getSchemaBuilder()->hasTable($p.'attachments')) {
                return [];
            }
            $folders = self::attachFolders($conn, $p);
            $out = [];
            foreach ($conn->table($p.'attachments')->whereIn('id_msg', $ids)->where('attachment_type', 0)
                ->when($conn->getSchemaBuilder()->hasColumn($p.'attachments', 'approved'), fn ($q) => $q->where('approved', 1))
                ->orderBy('id_attach')->get() as $a) {
                $out[$a->id_msg][] = self::attachFile($a, $folders) + [
                    'id' => (string) $a->id_attach, 'name' => self::decode((string) $a->filename), 'uid' => $a->id_member ?: null,
                    'url' => 'index.php?action=dlattach;attach='.$a->id_attach,
                ];
            }

            return $out;
        }];
    }
    /**
     * `personal_messages` (one row per message: sender, `deleted_by_sender`,
     * `id_pm_head` threading it) and `pm_recipients` (one row per recipient:
     * `bcc`, `is_read`, `deleted`). SMF removes the message once everybody
     * has deleted it; drafts live in `user_drafts`.
     */
    public static function privateMessages(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'personal_messages') && $conn->getSchemaBuilder()->hasTable($p.'pm_recipients');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'personal_messages')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'personal_messages')->where('id_pm', '>', $after)->orderBy('id_pm')->limit($limit)->get();
                $to = [];
                foreach ($rows->isEmpty() ? [] : $conn->table($p.'pm_recipients')->whereIn('id_pm', $rows->pluck('id_pm')->all())->get() as $r) {
                    $to[$r->id_pm][] = $r;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->id_pm;
                    $rs = $to[$m->id_pm] ?? [];
                    $open = array_filter($rs, fn ($r) => ! (int) ($r->bcc ?? 0));
                    $people = array_merge([$m->id_member_from], array_map(fn ($r) => $r->id_member, $open));
                    $keep = array_map(fn ($r) => $r->id_member, array_filter($rs, fn ($r) => ! (int) ($r->deleted ?? 0)));
                    if (! (int) ($m->deleted_by_sender ?? 0)) {
                        $keep[] = $m->id_member_from;
                    }
                    $out[] = [
                        'id' => (string) $m->id_pm,
                        'conv' => PrivateMessages::conv((string) ((int) ($m->id_pm_head ?? 0) ?: $m->id_pm), $people),
                        'title' => self::decode((string) ($m->subject ?? '')),
                        'uid' => $m->id_member_from,
                        'people' => $people,
                        'bcc' => array_map(fn ($r) => $r->id_member, array_filter($rs, fn ($r) => (bool) ($r->bcc ?? 0))),
                        'keep' => $keep,
                        'unread' => array_map(fn ($r) => $r->id_member, array_filter($rs, fn ($r) => ! (int) ($r->is_read ?? 1))),
                        'html' => self::body($m->body ?? ''),
                        'at' => Src::ts($m->msgtime ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * A topic points at its poll with `id_poll`; `poll_choices` are numbered
     * 0… within the poll and `log_polls` names each member's choice. Guest
     * votes are only in the totals, and SMF never shows who voted. The poll's
     * time is its topic's first post's.
     */
    public static function polls(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'polls') && $conn->getSchemaBuilder()->hasTable($p.'poll_choices');

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
                [$rows, $cursor, $done, $n] = Polls::page($conn->table($p.'polls'), 'id_poll', $after, $limit, $ctx);
                $ids = $rows->pluck('id_poll')->all();
                $topics = $ids ? $conn->table($p.'topics')->whereIn('id_poll', $ids)->get(['id_topic', 'id_poll', 'id_first_msg'])->keyBy('id_poll') : collect();
                $times = $topics->isEmpty() ? [] : $conn->table($p.'messages')->whereIn('id_msg', $topics->pluck('id_first_msg')->all())->pluck('poster_time', 'id_msg')->all();
                $options = $votes = [];
                foreach ($ids ? $conn->table($p.'poll_choices')->whereIn('id_poll', $ids)->orderBy('id_choice')->get(['id_poll', 'id_choice', 'label', 'votes']) : [] as $o) {
                    $options[$o->id_poll][(string) $o->id_choice] = ['text' => Polls::plain(self::body((string) $o->label)), 'count' => (int) $o->votes];
                }
                foreach ($ids && $conn->getSchemaBuilder()->hasTable($p.'log_polls') ? $conn->table($p.'log_polls')->whereIn('id_poll', $ids)->get(['id_poll', 'id_member', 'id_choice']) : [] as $v) {
                    $votes[$v->id_poll][] = [$v->id_member, (string) $v->id_choice, null];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $topic = $topics->get($poll->id_poll);
                    $at = Src::ts($topic ? ($times[$topic->id_first_msg] ?? null) : null);
                    $ends = Polls::at($poll->expire_time ?? 0);
                    $out[] = [
                        'id' => (string) $poll->id_poll,
                        'topic' => $topic ? (string) $topic->id_topic : null,
                        'question' => Polls::plain(self::body((string) $poll->question)),
                        'uid' => $poll->id_member ?? null,
                        'at' => $at,
                        'ends' => $ends ?? ((int) ($poll->voting_locked ?? 0) ? Polls::closedAt($at) : null),
                        'max' => max(1, (int) ($poll->max_votes ?? 1)),
                        'public' => false,
                        'change' => (bool) ($poll->change_vote ?? false),
                        // 2 = results shown once the poll has ended
                        'hide' => (int) ($poll->hide_results ?? 0) === 2,
                        'options' => $options[$poll->id_poll] ?? [],
                        'votes' => $votes[$poll->id_poll] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
