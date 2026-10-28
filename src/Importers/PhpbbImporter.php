<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * phpBB 3.x → Flarum.
 *   phpbb_forums → tags · phpbb_users → users · phpbb_topics → discussions · phpbb_posts → posts.
 */
class PhpbbImporter
{
    private static function prefix(array $cfg): string
    {
        return trim((string) ($cfg['prefix'] ?? 'phpbb_')) ?: 'phpbb_';
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['users', 'forums', 'topics', 'posts'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.phpbb', ['table' => $p.$req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'users')->count(),
            'categories' => (int) $conn->table($p.'forums')->count(),
            'topics' => (int) $conn->table($p.'topics')->count(),
            'posts' => (int) $conn->table($p.'posts')->count(),
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
                    $conn = $ctx->src();
                    $hasType = $conn->getSchemaBuilder()->hasColumn($p.'forums', 'forum_type');
                    $rows = $conn->table($p.'forums')->where('forum_id', '>', (int) $cursor)->orderBy('forum_id')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->forum_id;
                        if ($hasType && (int) ($f->forum_type ?? 1) !== 1) {
                            continue;
                        }
                        $map[$f->forum_id] = Dst::tag($f->forum_name ?: 'Forum', Src::tagSlug($f->forum_name ?: 'forum', (int) $f->forum_id), $f->forum_desc ?? null, null, (int) ($f->left_id ?? 0));
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
                    $rows = $ctx->src()->table($p.'users')->where('user_id', '>', (int) $cursor)->orderBy('user_id')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->user_id;
                        $email = trim((string) ($u->user_email ?? ''));
                        if ($email === '' || (int) ($u->user_type ?? 0) === 2) {
                            $skip++;

                            continue;
                        }

                        try {
                            $map[$u->user_id] = Dst::user(Src::username($u->username ?? null, (int) $u->user_id), $email, $u->user_password ?? null, Src::ts($u->user_regdate ?? null), null, Src::legacy($u->user_password ?? null), display: $u->username ?? null);
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
                fn () => (int) Src::connect($cfg)->table($p.'topics')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $conn = $ctx->src();
                    $visCol = $conn->getSchemaBuilder()->hasColumn($p.'topics', 'topic_visibility') ? 'topic_visibility' : null;
                    $rows = $conn->table($p.'topics')->where('topic_id', '>', (int) $cursor)->orderBy('topic_id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('topic_poster')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('forum_id')->all()) : [];
                    $known = $ctx->known('topic', $rows->pluck('topic_id'));
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->topic_id;
                        if (isset($known[(string) $t->topic_id]) || $visCol && (int) ($t->$visCol ?? 1) !== 1) {
                            continue;
                        }
                        $did = Dst::discussion($t->topic_title ?: 'Untitled', $userMap[(string) $t->topic_poster] ?? null, Src::ts($t->topic_time ?? null), (int) ($t->topic_type ?? 0) >= 1);
                        $map[$t->topic_id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->forum_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->forum_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                $p.'posts',
                'topic_id',
                'post_id',
                fn () => (int) Src::connect($cfg)->table($p.'posts')->count(),
                fn ($post) => [
                    'tid' => (int) $post->topic_id, 'pid' => (int) $post->post_id, 'uid' => $post->poster_id,
                    'html' => Bbcode::phpbb($post->post_text ?? '', (string) ($post->bbcode_uid ?? '')),
                    'at' => Src::ts($post->post_time ?? null),
                    'ok' => (int) ($post->post_visibility ?? $post->post_approved ?? 1) === 1,
                ],
                null,
                'post_edit_time',
            ),
        ], Phases::tail());
    }
    /**
     * Private messages: `privmsgs` (one row per message, threaded by
     * `root_level`) and `privmsgs_to` (one row per copy — each recipient's and
     * the author's own outbox/sent copy). phpBB removes a copy when its owner
     * deletes it, marks recipients' copies `pm_deleted` when the author
     * withdraws an unread message, and drops the message once no copy is
     * left. Drafts live in `drafts`, not here. Recipients are `u_<id>` /
     * `g_<id>` lists in `to_address` and `bcc_address`; a group's members
     * appear only as copies.
     */
    public static function privateMessages(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'privmsgs') && $conn->getSchemaBuilder()->hasTable($p.'privmsgs_to');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'privmsgs')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'privmsgs')->where('msg_id', '>', $after)->orderBy('msg_id')->limit($limit)->get();
                $copies = [];
                foreach ($rows->isEmpty() ? [] : $conn->table($p.'privmsgs_to')->whereIn('msg_id', $rows->pluck('msg_id')->all())->get() as $c) {
                    $copies[$c->msg_id][] = $c;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->msg_id;
                    $mine = $copies[$m->msg_id] ?? [];
                    if (! $mine) {
                        continue;
                    }
                    [$to] = self::addresses((string) ($m->to_address ?? ''));
                    [$bcc, $bccGroups] = self::addresses((string) ($m->bcc_address ?? ''));
                    // Copies nobody named: members of a group the message was sent to.
                    $others = array_diff(array_map(fn ($c) => (string) $c->user_id, $mine), $to, $bcc, [(string) $m->author_id]);
                    if ($bccGroups) { // a group blind-copied: its members stay blind
                        $bcc = array_merge($bcc, $others);
                    } else {
                        $to = array_merge($to, $others);
                    }
                    $people = array_merge([(string) $m->author_id], $to);
                    $out[] = [
                        'id' => (string) $m->msg_id,
                        'conv' => PrivateMessages::conv((string) ((int) ($m->root_level ?? 0) ?: $m->msg_id), $people),
                        'title' => (string) ($m->message_subject ?? ''),
                        'uid' => $m->author_id,
                        'people' => $people,
                        'bcc' => $bcc,
                        'keep' => array_map(fn ($c) => $c->user_id, array_filter($mine, fn ($c) => ! (int) ($c->pm_deleted ?? 0))),
                        'unread' => array_map(fn ($c) => $c->user_id, array_filter($mine, fn ($c) => (bool) ($c->pm_unread ?? 0))),
                        'html' => Bbcode::phpbb($m->message_text ?? '', (string) ($m->bbcode_uid ?? '')),
                        'at' => Src::ts($m->message_time ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * Polls live on the topic itself (`poll_title`, `poll_start`, …), with
     * `poll_options` numbered 1… within the topic and `poll_votes` naming the
     * member (or 1, the guest account). phpBB never shows who voted. The
     * question and options are BBCode under the first post's uid.
     */
    public static function polls(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'poll_options') && $conn->getSchemaBuilder()->hasColumn($p.'topics', 'poll_start');
        $polls = fn ($conn) => $conn->table($p.'topics')->where('poll_start', '>', 0)->where('poll_title', '!=', '');

        return [
            'count' => function () use ($cfg, $has, $polls) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $polls($conn)->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has, $polls) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                [$rows, $cursor, $done, $n] = Polls::page($polls($conn), 'topic_id', $after, $limit, $ctx);
                $ids = $rows->pluck('topic_id')->all();
                $options = $votes = [];
                foreach ($ids ? $conn->table($p.'poll_options')->whereIn('topic_id', $ids)->orderBy('poll_option_id')->get() : [] as $o) {
                    $options[$o->topic_id][] = $o;
                }
                foreach ($ids ? $conn->table($p.'poll_votes')->whereIn('topic_id', $ids)->get(['topic_id', 'poll_option_id', 'vote_user_id']) : [] as $v) {
                    $votes[$v->topic_id][] = [$v->vote_user_id, (string) $v->poll_option_id, null];
                }
                $firstPosts = $rows->pluck('topic_first_post_id')->filter()->all();
                $uids = $firstPosts ? $conn->table($p.'posts')->whereIn('post_id', $firstPosts)->pluck('bbcode_uid', 'post_id')->all() : [];

                $out = [];
                foreach ($rows as $t) {
                    $uid = (string) ($uids[$t->topic_first_post_id ?? 0] ?? '');
                    $opts = [];
                    foreach ($options[$t->topic_id] ?? [] as $o) {
                        $opts[(string) $o->poll_option_id] = ['text' => Polls::plain(Bbcode::phpbb((string) $o->poll_option_text, $uid)), 'count' => (int) $o->poll_option_total];
                    }
                    $out[] = [
                        'id' => (string) $t->topic_id,
                        'topic' => (string) $t->topic_id,
                        'question' => Polls::plain(Bbcode::phpbb((string) $t->poll_title, $uid)),
                        'uid' => $t->topic_poster ?? null,
                        'at' => Src::ts($t->poll_start),
                        'ends' => (int) ($t->poll_length ?? 0) > 0 ? Src::ts((int) $t->poll_start + (int) $t->poll_length) : null,
                        'max' => max(1, (int) ($t->poll_max_options ?? 1)),
                        'public' => false,
                        'change' => (bool) ($t->poll_vote_change ?? false),
                        'options' => $opts,
                        'votes' => $votes[$t->topic_id] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }

    /** @return array{0:list<string>,1:list<string>} user ids, group ids out of "u_2:u_5:g_4" */
    private static function addresses(string $list): array
    {
        $users = $groups = [];
        foreach (explode(':', $list) as $a) {
            if (preg_match('/^([ug])_(\d+)$/', trim($a), $m)) {
                if ($m[1] === 'u') {
                    $users[] = $m[2];
                } else {
                    $groups[] = $m[2];
                }
            }
        }

        return [$users, $groups];
    }
}
