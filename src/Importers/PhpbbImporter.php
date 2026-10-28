<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

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

    /** phpBB stores subjects and forum names HTML-escaped (request_var runs htmlspecialchars). */
    private static function decode(string $s): string
    {
        return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * 🚨 A moved topic leaves a SHADOW behind in the old forum (topic_moved_id
     * pointing at the real one, no posts of its own). Each one became an empty
     * duplicate discussion, and counted in the progress bar.
     */
    private static function topics(ConnectionInterface $conn, string $p): \Illuminate\Database\Query\Builder
    {
        $q = $conn->table($p.'topics');

        return $conn->getSchemaBuilder()->hasColumn($p.'topics', 'topic_moved_id') ? $q->where('topic_moved_id', 0) : $q;
    }

    /**
     * The forums GUESTS or REGISTERED may read (f_read = yes, set directly or
     * through a role), or null when the board has no ACL to go by. Everything
     * else is a staff forum and becomes a restricted tag. Three queries for the
     * whole board, whatever its size.
     *
     * @return array<string, true>|null
     */
    private static function readable(ConnectionInterface $conn, string $p): ?array
    {
        $sb = $conn->getSchemaBuilder();
        foreach (['acl_groups', 'acl_options', 'groups'] as $t) {
            if (! $sb->hasTable($p.$t)) {
                return null;
            }
        }
        $groups = $conn->table($p.'groups')->whereIn('group_name', ['GUESTS', 'REGISTERED'])->where('group_type', 3)->pluck('group_id')->all();
        $read = $conn->table($p.'acl_options')->where('auth_option', 'f_read')->value('auth_option_id');
        if (! $groups || ! $read || ! $conn->table($p.'acl_groups')->where('forum_id', '>', 0)->exists()) {
            return null;
        }
        $roles = $sb->hasTable($p.'acl_roles_data');
        $ids = $conn->table($p.'acl_groups')->whereIn('group_id', $groups)->where('forum_id', '>', 0)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('auth_option_id', $read)->where('auth_setting', 1))
                ->when($roles, fn ($q) => $q->orWhereIn('auth_role_id', $conn->table($p.'acl_roles_data')->where('auth_option_id', $read)->where('auth_setting', 1)->select('role_id'))))
            ->distinct()->pluck('forum_id');

        return array_fill_keys($ids->map(fn ($id) => (string) $id)->all(), true);
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
                    /*
                     * 🚨 The whole tree, once per batch (a board has dozens of
                     * forums, not thousands): a sub-forum's parent may have a
                     * higher id than it, and Flarum nests only one level, so
                     * every forum goes under its top-most ancestor. A category
                     * (forum_type 0) holds no topics but is that ancestor for
                     * the forums inside it, so it becomes their parent tag —
                     * dropping it lost the board's whole structure.
                     */
                    $conn = $ctx->src();
                    $forums = $conn->table($p.'forums')->orderBy('forum_id')->get()->keyBy('forum_id');
                    $type = fn ($f) => (int) ($f->forum_type ?? 1); // 0 category · 1 forum · 2 link
                    $top = [];
                    foreach ($forums as $id => $f) {
                        if ($type($f) === 1) {
                            for ($t = $f, $i = 0; ($up = $forums[(int) ($t->parent_id ?? 0)] ?? null) && $type($up) !== 2 && $i < 50; $i++) {
                                $t = $up;
                            }
                            $top[$id] = (int) $t->forum_id;
                        }
                    }
                    $parents = array_flip($top);
                    $readable = self::readable($conn, $p);
                    $made = [];
                    $tag = function ($f, ?int $parentId) use (&$made, $readable) {
                        $name = self::decode((string) $f->forum_name) ?: 'Forum';

                        return $made[$f->forum_id] ??= Dst::tag($name, Src::tagSlug($name, (int) $f->forum_id), self::decode((string) ($f->forum_desc ?? '')) ?: null, null, (int) ($f->left_id ?? 0), $parentId, $readable !== null && ! isset($readable[(string) $f->forum_id]));
                    };
                    $rows = $forums->filter(fn ($f) => $f->forum_id > (int) $cursor)->take($limit);
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->forum_id;
                        if (! isset($top[$f->forum_id]) && ! isset($parents[$f->forum_id])) {
                            continue; // a link, or a category with no forum in it
                        }
                        $up = $top[$f->forum_id] ?? $f->forum_id;
                        // A parent with a higher id is made now; its own turn finds it by slug.
                        $map[$f->forum_id] = $tag($f, $up === $f->forum_id ? null : $tag($forums[$up], null));
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
                    $rows = $conn->table($p.'users')->where('user_id', '>', (int) $cursor)->orderBy('user_id')->limit($limit)->get();
                    // 🚨 Banned members arrived free to post: the ban lives in
                    // `banlist` (ban_end 0 = for ever; ban_exclude = a whitelist row).
                    $bans = [];
                    if ($rows->isNotEmpty() && $conn->getSchemaBuilder()->hasColumn($p.'banlist', 'ban_userid')) {
                        foreach ($conn->table($p.'banlist')->whereIn('ban_userid', $rows->pluck('user_id')->all())->where('ban_exclude', 0)->get(['ban_userid', 'ban_end']) as $b) {
                            $end = (int) $b->ban_end === 0 ? Carbon::create(2038, 1, 1) : Src::ts($b->ban_end);
                            $bans[$b->ban_userid] = isset($bans[$b->ban_userid]) && $bans[$b->ban_userid]->gt($end) ? $bans[$b->ban_userid] : $end;
                        }
                    }
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->user_id;
                        $email = trim((string) ($u->user_email ?? ''));
                        // user_type 0 normal · 1 inactive (never activated, or deactivated) · 2 bots + guest · 3 founder
                        if ($email === '' || (int) ($u->user_type ?? 0) === 2) {
                            $skip++;

                            continue;
                        }

                        try {
                            $map[$u->user_id] = Dst::user(Src::username($u->username ?? null, (int) $u->user_id), $email, $u->user_password ?? null, Src::ts($u->user_regdate ?? null), null, Src::legacy($u->user_password ?? null), confirmed: (int) ($u->user_type ?? 0) !== 1, suspendedUntil: $bans[$u->user_id] ?? null, display: self::decode((string) ($u->username ?? '')) ?: null);
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
                fn () => (int) self::topics(Src::connect($cfg), $p)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $conn = $ctx->src();
                    $visCol = $conn->getSchemaBuilder()->hasColumn($p.'topics', 'topic_visibility') ? 'topic_visibility' : null;
                    $rows = self::topics($conn, $p)->where('topic_id', '>', (int) $cursor)->orderBy('topic_id')->limit($limit)->get();
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
                        // topic_type 1 sticky · 2 announce · 3 global; topic_status 1 = locked
                        $did = Dst::discussion(self::decode((string) $t->topic_title) ?: 'Untitled', $userMap[(string) $t->topic_poster] ?? null, Src::ts($t->topic_time ?? null), (int) ($t->topic_type ?? 0) >= 1, (int) ($t->topic_status ?? 0) === 1);
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
                fn ($post, ?Attachments $files = null) => [
                    'tid' => (int) $post->topic_id, 'pid' => (int) $post->post_id, 'uid' => $post->poster_id,
                    'html' => Bbcode::phpbb($post->post_text ?? '', (string) ($post->bbcode_uid ?? ''), $files?->resolver($post->post_id)),
                    'at' => Src::ts($post->post_time ?? null),
                    'ok' => (int) ($post->post_visibility ?? $post->post_approved ?? 1) === 1,
                ],
                null,
                'post_edit_time',
                self::attachments($cfg),
            ),
        ], Phases::tail());
    }

    /** One of the board's settings (`config`), or $default. */
    private static function setting(ConnectionInterface $conn, string $p, string $name, string $default = ''): string
    {
        static $cache = [];

        return $cache[$p.$name] ??= (string) (($conn->getSchemaBuilder()->hasTable($p.'config') ? $conn->table($p.'config')->where('config_name', $name)->value('config_value') : null) ?? $default);
    }

    /**
     * Groups: `groups` (group_type 3 = phpBB's own, by name) and `user_group`
     * (a pending request to join is not membership).
     */
    public static function groups(array $cfg): array
    {
        $p = self::prefix($cfg);
        $special = ['ADMINISTRATORS' => 'admin', 'GLOBAL_MODERATORS' => 'mod', 'REGISTERED' => 'member', 'REGISTERED_COPPA' => 'member', 'NEWLY_REGISTERED' => 'member', 'GUESTS' => 'guest', 'BOTS' => 'bots'];

        return [
            'needs' => [$p.'groups', $p.'user_group'],
            'groups' => fn (Ctx $ctx) => $ctx->src()->table($p.'groups')->orderBy('group_id')->get()->map(fn ($g) => [
                'id' => (string) $g->group_id,
                'name' => self::decode((string) $g->group_name),
                'color' => $g->group_colour ?? null,
                'role' => (int) ($g->group_type ?? 0) === 3 ? ($special[(string) $g->group_name] ?? null) : null,
            ])->all(),
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::pivot($ctx->src()->table($p.'users'), 'user_id', $after, $limit,
                fn (array $ids) => $ctx->src()->table($p.'user_group')->whereIn('user_id', $ids)->where('user_pending', 0)->get()->map(fn ($r) => [$r->user_id, $r->group_id])),
        ];
    }

    /**
     * Profile photos (`user_avatar_type`: upload, remote, gallery, gravatar —
     * 3.1+ names its drivers, 3.0 numbers them 1–3) and birthdays
     * (`user_birthday` "dd-mm-yyyy", the year 0 when not given).
     */
    public static function profiles(array $cfg): array
    {
        $p = self::prefix($cfg);

        return [
            'count' => fn () => (int) Src::connect($cfg)->table($p.'users')->count(),
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p) {
                $conn = $ctx->src();
                $salt = self::setting($conn, $p, 'avatar_salt');
                $uploads = trim(self::setting($conn, $p, 'avatar_path', 'images/avatars/upload'), '/');
                $gallery = trim(self::setting($conn, $p, 'avatar_gallery_path', 'images/avatars/gallery'), '/');

                return Profiles::page($conn->table($p.'users'), 'user_id', $after, $limit, function ($u) use ($salt, $uploads, $gallery) {
                    $file = (string) ($u->user_avatar ?? '');
                    $type = (string) ($u->user_avatar_type ?? '');
                    $avatar = $file === '' ? null : match ($type) {
                        // Stored as <salt>_<user id>.<ext>; served by download/file.php, as the folder itself is closed.
                        'avatar.driver.upload', '1' => ['root' => $uploads, 'path' => $salt.'_'.$u->user_id.'.'.pathinfo(explode('_', $file, 2)[1] ?? $file, PATHINFO_EXTENSION), 'url' => 'download/file.php?avatar='.rawurlencode($file)],
                        'avatar.driver.remote', '2' => ['url' => $file],
                        'avatar.driver.local', '3' => ['root' => $gallery, 'path' => $file, 'url' => $gallery.'/'.$file],
                        'avatar.driver.gravatar' => Profiles::gravatar($file),
                        default => null,
                    };

                    return ['avatar' => $avatar, 'birthday' => Profiles::date((string) ($u->user_birthday ?? ''), 'dmy')];
                });
            },
        ];
    }

    /**
     * Attachments (`attachments`, in_message 0): the files are in `files/`
     * under a random physical name, and served by download/file.php. A post
     * places one with [attachment=n], n counting from its newest attachment,
     * as phpBB numbers them while the post is written.
     */
    public static function attachments(array $cfg): array
    {
        $p = self::prefix($cfg);

        return ['fetch' => function (array $ids, Ctx $ctx) use ($p) {
            $conn = $ctx->src();
            if (! $conn->getSchemaBuilder()->hasTable($p.'attachments')) {
                return [];
            }
            $dir = trim(self::setting($conn, $p, 'upload_path', 'files'), '/');
            $out = [];
            foreach ($conn->table($p.'attachments')->whereIn('post_msg_id', $ids)->where('in_message', 0)->where('is_orphan', 0)
                ->orderByDesc('filetime')->orderByDesc('attach_id')->get() as $a) {
                $i = count($out[$a->post_msg_id] ?? []);
                $out[$a->post_msg_id][] = [
                    'id' => (string) $a->attach_id, 'name' => self::decode((string) $a->real_filename), 'uid' => $a->poster_id ?? null,
                    'root' => $dir, 'path' => basename((string) $a->physical_filename), 'url' => 'download/file.php?id='.$a->attach_id,
                    'keys' => ['i'.$i],
                ];
            }

            return $out;
        }, 'inline' => '#/download/file\.php$#'];
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
