<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;

/**
 * Invision Community 4.x / 5.x (IP.Board) → Flarum.
 *   forums_forums → tags (titles live in core_sys_lang_words: forums_forum_{id})
 *   core_members  → users (bcrypt copies straight; legacy md5 → reset)
 *   forums_topics → discussions · forums_posts → posts
 * IPS post content is HTML (not BBCode); ipsHtml() cleans the IPS-specific markup.
 */
class InvisionImporter
{
    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['core_members', 'forums_forums', 'forums_topics', 'forums_posts'] as $req) {
            if (! $sb->hasTable($req)) {
                throw new \RuntimeException(Src::t('not_source.invision', ['table' => $req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table('core_members')->where('member_id', '>', 0)->count(),
            'categories' => (int) $conn->table('forums_forums')->count(),
            'topics' => (int) $conn->table('forums_topics')->where('approved', 1)->count(),
            'posts' => (int) $conn->table('forums_posts')->where('queued', 0)->count(),
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
                fn () => $hasTags ? (int) Src::connect($cfg)->table('forums_forums')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $conn = $ctx->src();
                    $rows = $conn->table('forums_forums')->where('id', '>', (int) $cursor)->orderBy('id')->limit($limit)->get();
                    [$names, $descs] = self::forumWords($conn, $rows->pluck('id')->all());
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $cursor = $f->id;
                        if ((int) ($f->redirect_on ?? 0) === 1 && trim((string) ($f->redirect_url ?? '')) !== '') {
                            continue; // redirect-only forum — no content
                        }
                        $name = $names[(int) $f->id] ?? (($f->name_seo ?? '') ? \Illuminate\Support\Str::headline((string) $f->name_seo) : ('Forum '.$f->id));
                        $map[$f->id] = Dst::tag($name, Src::tagSlug($name, (int) $f->id), $descs[(int) $f->id] ?? null, $f->feature_color ?? null, (int) ($f->position ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table('core_members')->where('member_id', '>', 0)->count(),
                function ($cursor, $limit, Ctx $ctx) {
                    $rows = $ctx->src()->table('core_members')->where('member_id', '>', (int) $cursor)->orderBy('member_id')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->member_id;
                        $email = trim((string) ($u->email ?? ''));
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        try {
                            $map[$u->member_id] = Dst::user(Src::username($u->name ?? null, (int) $u->member_id), $email, null, Src::ts($u->joined ?? null), null, ($u->members_pass_hash ?? '') !== '' ? ['scheme' => 'ips', 'hash' => (string) $u->members_pass_hash, 'salt' => (string) ($u->members_pass_salt ?? '')] : null);
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
                fn () => (int) Src::connect($cfg)->table('forums_topics')->where('approved', 1)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($hasTags) {
                    $rows = $ctx->src()->table('forums_topics')->where('approved', 1)->where('tid', '>', (int) $cursor)->orderBy('tid')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('starter_id')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('forum_id')->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck('tid'));
                    foreach ($rows as $t) {
                        $cursor = $t->tid;
                        if (isset($known[(string) $t->tid])) {
                            continue;
                        }
                        if (($t->state ?? 'open') === 'link' || trim((string) ($t->moved_to ?? '')) !== '') {
                            continue; // moved/redirect pointer
                        }
                        $did = Dst::discussion($t->title ?: 'Untitled', $userMap[(string) $t->starter_id] ?? null, Src::ts($t->start_date ?? null), (bool) ($t->pinned ?? false), ($t->state ?? 'open') === 'closed');
                        $map[$t->tid] = $did;
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
                'forums_posts',
                'topic_id',
                'pid',
                fn () => (int) Src::connect($cfg)->table('forums_posts')->where('queued', 0)->count(),
                fn ($post) => [
                    'tid' => (int) $post->topic_id, 'pid' => (int) $post->pid, 'uid' => $post->author_id,
                    'html' => self::ipsHtml($post->post ?? '', (string) ($cfg['assets_base'] ?? '')), 'at' => Src::ts($post->post_date ?? null),
                    'ok' => (int) ($post->pdelete_time ?? 0) === 0,
                ],
                fn ($q) => $q->where('queued', 0),
                'edit_time',
            ),
        ], Phases::tail());
    }

    /**
     * Forum titles/descriptions are translatable strings in core_sys_lang_words,
     * keyed forums_forum_{id} / forums_forum_{id}_desc.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [names, descs]
     */
    /**
     * Messenger: `core_message_topics` (a conversation: `mt_title`,
     * `mt_is_draft`), `core_message_posts` (its messages) and
     * `core_message_topic_user_map` (a row per participant: `map_user_active`
     * 0 once they left or were removed, `map_read_time`). Drafts never went out.
     */
    public static function privateMessages(array $cfg): array
    {
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable('core_message_posts') && $conn->getSchemaBuilder()->hasTable('core_message_topic_user_map');

        return [
            'count' => function () use ($cfg, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table('core_message_posts')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($has, $cfg) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table('core_message_posts')->where('msg_id', '>', $after)->orderBy('msg_id')->limit($limit)->get();
                $ids = $rows->pluck('msg_topic_id')->unique()->values()->all();
                $topics = $ids ? $conn->table('core_message_topics')->whereIn('mt_id', $ids)->get()->keyBy('mt_id') : collect();
                $who = [];
                foreach ($ids ? $conn->table('core_message_topic_user_map')->whereIn('map_topic_id', $ids)->get() : [] as $r) {
                    $who[$r->map_topic_id][] = $r;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->msg_id;
                    $t = $topics->get($m->msg_topic_id);
                    if (! $t || (int) ($t->mt_is_draft ?? 0)) {
                        continue;
                    }
                    $rs = $who[$m->msg_topic_id] ?? [];
                    $active = array_filter($rs, fn ($r) => (int) ($r->map_user_active ?? 1) === 1);
                    $out[] = [
                        'id' => (string) $m->msg_id,
                        'conv' => (string) $m->msg_topic_id,
                        'title' => (string) ($t->mt_title ?? ''),
                        'uid' => $m->msg_author_id,
                        'people' => array_map(fn ($r) => $r->map_user_id, $rs),
                        'keep' => array_map(fn ($r) => $r->map_user_id, $active),
                        'unread' => array_map(fn ($r) => $r->map_user_id, array_filter($active, fn ($r) => (int) ($r->map_read_time ?? 0) < (int) $m->msg_date)),
                        'html' => self::ipsHtml($m->msg_post ?? '', (string) ($cfg['assets_base'] ?? '')),
                        'at' => Src::ts($m->msg_date ?? null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    private static function forumWords(ConnectionInterface $conn, array $ids): array
    {
        $names = $descs = [];
        if (! $ids || ! $conn->getSchemaBuilder()->hasTable('core_sys_lang_words')) {
            return [$names, $descs];
        }
        $keys = [];
        foreach ($ids as $id) {
            $keys[] = 'forums_forum_'.(int) $id;
            $keys[] = 'forums_forum_'.(int) $id.'_desc';
        }
        $words = $conn->table('core_sys_lang_words')->where('word_app', 'forums')->whereIn('word_key', $keys)
            ->orderBy('lang_id')->get(['word_key', 'word_default', 'word_custom']);
        foreach ($words as $w) {
            $val = ($w->word_custom !== null && $w->word_custom !== '') ? $w->word_custom : $w->word_default;
            if ($val === null || $val === '') {
                continue;
            }
            if (preg_match('/^forums_forum_(\d+)_desc$/', $w->word_key, $m)) {
                $descs[(int) $m[1]] ??= $val;
            } elseif (preg_match('/^forums_forum_(\d+)$/', $w->word_key, $m)) {
                $names[(int) $m[1]] ??= $val;
            }
        }

        return [$names, $descs];
    }

    /**
     * IPS post HTML, ready for {@see \ErnestDefoe\Importer\Markup\Html::clean()}.
     *
     * IPS stores its own addresses as placeholders — `<fileStore.core_Attachment>`
     * for uploads, `<___base_url___>` for the board — that only its renderer
     * fills in. With the old board's address they become real links and images;
     * without it, an image falls back to its name rather than breaking.
     * Quotes, spoilers, code languages, mentions and emoticons are recognised
     * by their IPS classes in Html::clean().
     */
    public static function ipsHtml(?string $html, string $base = ''): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $base = rtrim($base, '/');

        return preg_replace_callback('#(?:&lt;|<)(fileStore\.[\w.]+|___base_url___)(?:&gt;|>)/?#', function ($m) use ($base) {
            if ($base === '') {
                return '';
            }

            return $m[1] === '___base_url___' ? $base.'/' : $base.'/uploads/';
        }, $html) ?? $html;
    }

    /**
     * `core_polls` holds a topic's poll (`forums_topics.poll_state` points at
     * it). One IPS poll can ask several questions, each with its own choices
     * and totals in the `choices` JSON (serialized PHP before 4.x); each
     * becomes a poll of its own on the first post. `core_voters` keeps a
     * member's picks per question in `member_choices`.
     */
    public static function polls(array $cfg): array
    {
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable('core_polls') && $conn->getSchemaBuilder()->hasColumn('forums_topics', 'poll_state');
        $decode = function (?string $v): array {
            $v = (string) $v;
            $out = json_decode($v, true);
            if (! is_array($out)) {
                $out = @unserialize($v, ['allowed_classes' => false]);
            }

            return is_array($out) ? $out : [];
        };

        return [
            'count' => function () use ($cfg, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table('core_polls')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($has, $decode) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                [$rows, $cursor, $done, $n] = Polls::page($conn->table('core_polls'), 'pid', $after, $limit, $ctx);
                $ids = $rows->pluck('pid')->all();
                $topics = $ids ? $conn->table('forums_topics')->whereIn('poll_state', $ids)->pluck('tid', 'poll_state')->all() : [];
                $votes = [];
                foreach ($ids && $conn->getSchemaBuilder()->hasTable('core_voters') ? $conn->table('core_voters')->whereIn('poll', $ids)->get(['poll', 'member_id', 'member_choices', 'vote_date']) : [] as $v) {
                    foreach ($decode($v->member_choices) as $q => $picks) {
                        foreach ((array) $picks as $pick) {
                            $votes[$v->poll][(string) $q][] = [$v->member_id, (string) $pick, Polls::at($v->vote_date)];
                        }
                    }
                }

                $out = [];
                foreach ($rows as $poll) {
                    $at = Src::ts($poll->start_date ?? null);
                    $ends = Polls::at($poll->poll_close_date ?? 0) ?? ((int) ($poll->poll_closed ?? 0) ? Polls::closedAt($at) : null);
                    $first = true;
                    foreach ($decode($poll->choices) as $q => $question) {
                        $opts = [];
                        foreach ((array) ($question['choice'] ?? []) as $k => $text) {
                            $opts[(string) $k] = ['text' => Polls::plain((string) $text), 'count' => (int) ($question['votes'][$k] ?? 0)];
                        }
                        $out[] = [
                            // The first question is mapped under the poll's own id, which is
                            // how a later page knows the whole poll is here already; they
                            // are all written in the same transaction.
                            'id' => $first ? (string) $poll->pid : $poll->pid.':'.$q,
                            'topic' => isset($topics[$poll->pid]) ? (string) $topics[$poll->pid] : null,
                            'question' => Polls::plain((string) (($question['question'] ?? '') ?: $poll->poll_question)),
                            'uid' => $poll->starter_id ?? null,
                            'at' => $at,
                            'ends' => $ends,
                            'max' => ! empty($question['multi']) ? 0 : 1,
                            'public' => (bool) ($poll->poll_view_voters ?? false),
                            'change' => false,
                            'options' => $opts,
                            'votes' => $votes[$poll->pid][(string) $q] ?? [],
                        ];
                        $first = false;
                    }
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
