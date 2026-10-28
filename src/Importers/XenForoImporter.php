<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * XenForo 1.x / 2.x → Flarum.
 *   xf_node (Forum, and a Category that holds one) → tags · xf_user → users · xf_thread → discussions · xf_post → posts
 * Passwords come from xf_user_authenticate (XF2 stores the bcrypt under `hash`);
 * anything non-bcrypt is reset. Post bodies are BBCode.
 */
class XenForoImporter
{
    /**
     * XenForo's xf_user_authenticate row → [bcrypt hash to copy, or a legacy
     * password to check at first sign-in]. XenForo 2 (XF:Core12) is bcrypt of
     * the password itself; XenForo 1 hashed twice with a salt; boards XenForo
     * imported keep that board's hash under XF:vBulletin, XF:PhpBb3 and so on.
     *
     * @return array{0:?string,1:?array}
     */
    private static function password(string $class, string $data): array
    {
        // allowed_classes:false — never instantiate objects from a foreign dump
        // (a crafted payload could otherwise trigger a POP chain). JSON on XF 2.2+.
        try {
            $d = str_starts_with(ltrim($data), '{') ? json_decode($data, true) : unserialize($data, ['allowed_classes' => false]);
        } catch (\Throwable) {
            $d = null;
        }
        if (! is_array($d) || empty($d['hash']) || ! is_string($d['hash'])) {
            return [null, null];
        }
        $hash = $d['hash'];
        $salt = is_string($d['salt'] ?? null) ? $d['salt'] : '';
        $class = strtolower(str_replace(['XenForo_Authentication_', 'XF:', '\\'], ['', '', ''], $class));

        return match ($class) {
            'core12', '' => preg_match('#^\$2[aby]\$#', $hash) ? [$hash, null] : [null, Src::legacy($hash)],
            'core' => [null, ['scheme' => 'xf1', 'hash' => $hash, 'salt' => $salt]],
            'vbulletin' => [null, ['scheme' => 'vb', 'hash' => $hash, 'salt' => $salt]],
            'phpbb3' => [null, Src::legacy($hash) ?? ['scheme' => 'native', 'hash' => $hash, 'salt' => null]],
            'mybb' => [null, ['scheme' => 'mybb', 'hash' => $hash, 'salt' => $salt]],
            'ipboard', 'ipboard40' => [null, ['scheme' => 'ips', 'hash' => $hash, 'salt' => $salt]],
            default => [null, null],
        };
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['xf_user', 'xf_thread', 'xf_post'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException(Src::t('not_source.xenforo', ['table' => $req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('xf_user')->count(),
            'categories' => $sb->hasTable('xf_node') ? (int) $conn->table('xf_node')->whereIn('node_type_id', ['Forum', 'Category'])->count() : 0,
            'topics' => (int) self::threads($conn)->count(),
            'posts' => (int) $conn->table('xf_post')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase(
                'tags',
                'categories',
                fn () => ($hasTags && Src::connect($cfg)->getSchemaBuilder()->hasTable('xf_node')) ? (int) Src::connect($cfg)->table('xf_node')->whereIn('node_type_id', ['Forum', 'Category'])->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $conn = $ctx->src();
                    if (! $hasTags || ! $conn->getSchemaBuilder()->hasTable('xf_node')) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    /*
                     * 🚨 The whole node tree, once per batch (a board has a few
                     * hundred nodes at most). Sub-forums came across as top-level
                     * tags and Category nodes not at all, so a board's sections
                     * were flattened into one long list. A Forum is filed under
                     * the root-most Category or Forum above it — Flarum nests
                     * one level, so deeper sub-forums fold into that root — and a
                     * Category is a tag only when it holds a forum.
                     */
                    $nodes = $conn->table('xf_node')->get()->keyBy('node_id');
                    $root = function (int $id) use ($nodes) {
                        $top = null;
                        for ($seen = []; ($n = $nodes->get($id)) && ! isset($seen[$id]); $id = (int) $n->parent_node_id) {
                            $seen[$id] = true;
                            $top = in_array($n->node_type_id, ['Forum', 'Category'], true) ? $n : $top;
                        }

                        return $top;
                    };
                    $tops = [];
                    foreach ($nodes as $n) {
                        if ($n->node_type_id === 'Forum' && ($t = $root((int) $n->node_id))) {
                            $tops[$t->node_id] = true;
                        }
                    }
                    $denied = self::nodeDenied($conn, $nodes);
                    $make = fn ($n, ?int $parent) => Dst::tag($n->title ?: 'Forum', Src::tagSlug($n->title ?: 'forum', (int) $n->node_id), $n->description ?? null, null, (int) ($n->display_order ?? 0), $parent, $denied((int) $n->node_id));

                    $rows = $nodes->filter(fn ($n) => in_array($n->node_type_id, ['Forum', 'Category'], true) && (int) $n->node_id > (int) $cursor)
                        ->sortBy('node_id')->take($limit)->values();
                    $parents = [];
                    foreach ($rows as $c) {
                        if (($t = $root((int) $c->node_id)) && (int) $t->node_id !== (int) $c->node_id) {
                            $parents[$c->node_id] = $t;
                        }
                    }
                    $known = $ctx->mapGet('tag', array_map(fn ($t) => $t->node_id, $parents));
                    $map = $made = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->node_id;
                        if ($c->node_type_id === 'Category' && ! isset($tops[$c->node_id])) {
                            continue; // an empty section, or one nested inside another
                        }
                        $parent = null;
                        if ($t = $parents[$c->node_id] ?? null) {
                            // A parent with a higher id than its child is made now; its
                            // own row later finds it by slug and maps it then.
                            $parent = $map[$t->node_id] ?? $known[(string) $t->node_id] ?? ($made[$t->node_id] ??= $make($t, null));
                        }
                        $map[$c->node_id] = $make($c, $parent);
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table('xf_user')->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $conn = $ctx->src();
                    $rows = $conn->table('xf_user')->where('user_id', '>', (int) $cursor)->orderBy('user_id')->limit($limit)->get();
                    $pw = $legacy = [];
                    if (count($rows) && $conn->getSchemaBuilder()->hasTable('xf_user_authenticate')) {
                        foreach ($conn->table('xf_user_authenticate')->whereIn('user_id', $rows->pluck('user_id')->all())->get(['user_id', 'scheme_class', 'data']) as $a) {
                            [$hash, $legacy[$a->user_id]] = self::password((string) $a->scheme_class, (string) $a->data);
                            if ($hash !== null) {
                                $pw[$a->user_id] = $hash;
                            }
                        }
                    }
                    /*
                     * 🚨 Every account arrived confirmed and free to post: one
                     * still waiting on its confirmation email or on approval, a
                     * banned member and a registration the staff had rejected.
                     * Bans live in xf_user_ban (end_date 0 = for good); a
                     * rejected sign-up with nothing posted is not brought at all.
                     */
                    $bans = $posters = [];
                    $banned = $rows->filter(fn ($u) => ! empty($u->is_banned))->pluck('user_id')->all();
                    if ($banned && $conn->getSchemaBuilder()->hasTable('xf_user_ban')) {
                        $bans = $conn->table('xf_user_ban')->whereIn('user_id', $banned)->pluck('end_date', 'user_id')->all();
                    }
                    $rejected = $rows->where('user_state', 'rejected')->pluck('user_id')->all();
                    if ($rejected) {
                        $posters = array_flip($conn->table('xf_post')->whereIn('user_id', $rejected)->distinct()->pluck('user_id')->all());
                    }
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->user_id;
                        $email = trim((string) ($u->email ?? ''));
                        $state = (string) ($u->user_state ?? 'valid');
                        if ($email === '' || ($state === 'rejected' && ! isset($posters[$u->user_id]))) {
                            $skip++;

                            continue;
                        }
                        $until = null;
                        if ($state === 'rejected') {
                            $until = Carbon::create(2038, 1, 1);
                        } elseif (! empty($u->is_banned)) {
                            $end = (int) ($bans[$u->user_id] ?? 0);
                            $until = $end > 0 ? Carbon::createFromTimestamp($end) : Carbon::create(2038, 1, 1);
                        }
                        $confirmed = ! in_array($state, ['email_confirm', 'email_confirm_edit', 'moderated'], true);

                        try {
                            $map[$u->user_id] = Dst::user(Src::username($u->username ?? null, (int) $u->user_id), $email, $pw[$u->user_id] ?? null, Src::ts($u->register_date ?? null), null, $legacy[$u->user_id] ?? null, $confirmed, $until, display: $u->username ?? null);
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
                fn () => (int) self::threads(Src::connect($cfg))->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = self::threads($ctx->src())->where('thread_id', '>', (int) $cursor)->orderBy('thread_id')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('user_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('node_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('thread_id'));
                    foreach ($rows as $t) {
                        $cursor = $t->thread_id;
                        if (isset($known[(string) $t->thread_id])) {
                            continue;
                        }
                        if (($t->discussion_state ?? 'visible') !== 'visible') {
                            continue;
                        }
                        // A locked thread (discussion_open 0) arrived open to replies.
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->user_id] ?? null, Src::ts($t->post_date ?? null), (bool) ($t->sticky ?? false), (int) ($t->discussion_open ?? 1) === 0);
                        $map[$t->thread_id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->node_id])) {
                            Dst::attachTag($did, $tagMap[(string) $t->node_id]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                'xf_post',
                'thread_id',
                'post_id',
                fn () => (int) Src::connect($cfg)->table('xf_post')->count(),
                fn ($post) => [
                    'tid' => (int) $post->thread_id, 'pid' => (int) $post->post_id, 'uid' => $post->user_id,
                    'html' => Bbcode::toHtml($post->message ?? ''), 'at' => Src::ts($post->post_date ?? null),
                    'ok' => ($post->message_state ?? 'visible') === 'visible',
                ],
                null,
                'last_edit_date',
            ),
        ], Phases::tail());
    }
    /**
     * The threads that are discussions. 🚨 A thread moved with "leave a
     * redirect" stays behind as discussion_type 'redirect' with no posts of
     * its own: it was imported as an empty discussion beside the real one.
     */
    private static function threads(ConnectionInterface $conn): Builder
    {
        $q = $conn->table('xf_thread');

        return $conn->getSchemaBuilder()->hasColumn('xf_thread', 'discussion_type')
            ? $q->where(fn ($w) => $w->whereNull('discussion_type')->orWhere('discussion_type', '<>', 'redirect'))
            : $q;
    }

    /**
     * Whether a node was closed to guests (group 1) AND registered members
     * (group 2) — a staff forum. One query for every node.
     *
     * XenForo keeps per-node overrides in xf_permission_entry_content: 'reset'
     * (revoke what the group had) or 'deny' takes viewing away, 'content_allow'
     * gives it back. The nearest node up the tree that says anything decides,
     * except that a 'deny' anywhere on the way wins, as XenForo resolves it.
     *
     * @param  Collection<int|string,\stdClass>  $nodes  every xf_node row by id
     * @return \Closure(int):bool
     */
    private static function nodeDenied(ConnectionInterface $conn, Collection $nodes): \Closure
    {
        $entries = [];
        if ($conn->getSchemaBuilder()->hasTable('xf_permission_entry_content')) {
            $q = $conn->table('xf_permission_entry_content')->where('content_type', 'node')->where('permission_group_id', 'forum')
                ->whereIn('permission_id', ['view', 'viewContent'])->whereIn('user_group_id', [1, 2]);
            if ($conn->getSchemaBuilder()->hasColumn('xf_permission_entry_content', 'user_id')) {
                $q->where('user_id', 0);
            }
            foreach ($q->get(['content_id', 'user_group_id', 'permission_value']) as $e) {
                $entries[(int) $e->content_id][(int) $e->user_group_id][] = (string) $e->permission_value;
            }
        }
        $blocked = function (int $id, int $group) use ($nodes, $entries): bool {
            $verdict = null;
            for ($seen = []; ($n = $nodes->get($id)) && ! isset($seen[$id]); $id = (int) $n->parent_node_id) {
                $seen[$id] = true;
                $v = $entries[$id][$group] ?? [];
                if (in_array('deny', $v, true)) {
                    return true;
                }
                if ($v && $verdict === null && (in_array('content_allow', $v, true) || in_array('reset', $v, true))) {
                    $verdict = ! in_array('content_allow', $v, true);
                }
            }

            return (bool) $verdict;
        };

        return fn (int $id) => $entries && $blocked($id, 1) && $blocked($id, 2);
    }

    /**
     * Conversations: `xf_conversation_master` (title, starter),
     * `xf_conversation_message` and `xf_conversation_recipient` — one row per
     * participant, `recipient_state` 'active', or 'deleted' / 'deleted_ignored'
     * once they deleted or left it, and `last_read_date`. XenForo has no drafts
     * in these tables and never deletes single messages.
     */
    public static function privateMessages(array $cfg): array
    {
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable('xf_conversation_message') && $conn->getSchemaBuilder()->hasTable('xf_conversation_recipient');

        return [
            'count' => function () use ($cfg, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table('xf_conversation_message')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $state = $conn->getSchemaBuilder()->hasColumn('xf_conversation_message', 'message_state');
                $rows = $conn->table('xf_conversation_message')->where('message_id', '>', $after)->orderBy('message_id')->limit($limit)->get();
                $convIds = $rows->pluck('conversation_id')->unique()->values()->all();
                $titles = $convIds ? $conn->table('xf_conversation_master')->whereIn('conversation_id', $convIds)->pluck('title', 'conversation_id')->all() : [];
                $who = [];
                foreach ($convIds ? $conn->table('xf_conversation_recipient')->whereIn('conversation_id', $convIds)->get() : [] as $r) {
                    $who[$r->conversation_id][] = $r;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->message_id;
                    if ($state && ($m->message_state ?? 'visible') !== 'visible') {
                        continue;
                    }
                    $rs = $who[$m->conversation_id] ?? [];
                    $active = array_filter($rs, fn ($r) => ($r->recipient_state ?? 'active') === 'active');
                    $out[] = [
                        'id' => (string) $m->message_id,
                        'conv' => (string) $m->conversation_id,
                        'thread' => (string) $m->conversation_id,
                        'title' => (string) ($titles[$m->conversation_id] ?? ''),
                        'uid' => $m->user_id,
                        'people' => array_map(fn ($r) => $r->user_id, $rs),
                        'keep' => array_map(fn ($r) => $r->user_id, $active),
                        'unread' => array_map(fn ($r) => $r->user_id, array_filter($active, fn ($r) => (int) ($r->last_read_date ?? 0) < (int) $m->message_date)),
                        'html' => Bbcode::toHtml($m->message ?? ''),
                        'at' => Src::ts($m->message_date ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /**
     * `xf_poll` on a thread (content_type 'thread'), its `xf_poll_response`
     * rows in order and one `xf_poll_vote` per member and choice. XenForo 1
     * has `multiple` where 2 has `max_votes` (0 = any number) and `change_vote`.
     */
    public static function polls(array $cfg): array
    {
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable('xf_poll') && $conn->getSchemaBuilder()->hasTable('xf_poll_response');
        $polls = fn ($conn) => $conn->table('xf_poll')->where('content_type', 'thread');

        return [
            'count' => function () use ($cfg, $has, $polls) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $polls($conn)->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($has, $polls) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                [$rows, $cursor, $done, $n] = Polls::page($polls($conn), 'poll_id', $after, $limit, $ctx);
                $ids = $rows->pluck('poll_id')->all();
                $options = $votes = [];
                foreach ($ids ? $conn->table('xf_poll_response')->whereIn('poll_id', $ids)->orderBy('poll_response_id')->get(['poll_response_id', 'poll_id', 'response', 'response_vote_count']) : [] as $o) {
                    $options[$o->poll_id][(string) $o->poll_response_id] = ['text' => (string) $o->response, 'count' => (int) $o->response_vote_count];
                }
                foreach ($ids && $conn->getSchemaBuilder()->hasTable('xf_poll_vote') ? $conn->table('xf_poll_vote')->whereIn('poll_id', $ids)->get(['poll_id', 'user_id', 'poll_response_id', 'vote_date']) : [] as $v) {
                    $votes[$v->poll_id][] = [$v->user_id, (string) $v->poll_response_id, Polls::at($v->vote_date)];
                }
                $threads = $ids ? $conn->table('xf_thread')->whereIn('thread_id', $rows->pluck('content_id')->all())->get(['thread_id', 'user_id', 'post_date'])->keyBy('thread_id') : collect();

                $out = [];
                foreach ($rows as $poll) {
                    $thread = $threads->get($poll->content_id);
                    $out[] = [
                        'id' => (string) $poll->poll_id,
                        'topic' => (string) $poll->content_id,
                        'question' => (string) $poll->question,
                        'uid' => $thread->user_id ?? null,
                        'at' => Src::ts($thread->post_date ?? null),
                        'ends' => Polls::at($poll->close_date ?? 0),
                        'max' => property_exists($poll, 'max_votes') ? (int) $poll->max_votes : ((int) ($poll->multiple ?? 0) ? 0 : 1),
                        'public' => (bool) ($poll->public_votes ?? false),
                        'change' => (bool) ($poll->change_vote ?? false),
                        'options' => $options[$poll->poll_id] ?? [],
                        'votes' => $votes[$poll->poll_id] ?? [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
