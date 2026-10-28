<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Web Wiz Forums (ASP / SQL Server) → Flarum.
 *   tblForum → tags · tblAuthor → users · tblTopic → discussions · tblThread → posts.
 *
 * 🚨 Web Wiz's vocabulary is inverted relative to every other platform here:
 * a "Topic" is the DISCUSSION and a "Thread" is an individual POST. tblThread
 * joins back to tblTopic on Topic_ID.
 *
 * Two ways in, because Web Wiz runs on Microsoft SQL Server:
 *   1. A live connection (driver `sqlsrv`) — needs pdo_sqlsrv on this server,
 *      which most LAMP hosting does not have. Src::connect says so plainly.
 *   2. An uploaded export converted to SQLite — the route that works anywhere.
 *
 * 🚨 COLUMN NAMES ARE RESOLVED AT RUNTIME, not hardcoded. Web Wiz's schema
 * drifted across 7.x/8.x/9.x/10.x and installs vary, so every non-obvious
 * column is looked up from a candidate list against the actual source schema.
 * test() reports what it resolved, so an admin sees the mapping BEFORE running
 * an import rather than discovering empty posts afterwards. If a required
 * column can't be found the import refuses to start and names the column.
 *
 * Passwords are not portable (Web Wiz salts and hashes its own way), so
 * members reset — same as every other importer here.
 */
class WebWizImporter
{
    /** Web Wiz ships its tables as tblAuthor, tblTopic, … */
    private static function prefix(array $cfg): string
    {
        $p = (string) ($cfg['prefix'] ?? 'tbl');

        return $p === '' ? 'tbl' : $p;
    }

    /**
     * First column from $candidates that actually exists on $table.
     * Returns null when none match.
     */
    private static function col(ConnectionInterface $conn, string $table, array $candidates): ?string
    {
        try {
            $have = array_map('strtolower', $conn->getSchemaBuilder()->getColumnListing($table));
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($candidates as $c) {
            if (in_array(strtolower($c), $have, true)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Resolve every column this importer reads. Kept in one place so the
     * mapping is inspectable (test() returns it) and correctable in one edit
     * if a given Web Wiz version names something differently.
     *
     * @return array{cols: array<string, string|null>, missing: array<int, string>}
     */
    public static function schema(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);

        $cols = [
            // tblAuthor
            'author_id' => self::col($conn, $p.'Author', ['Author_ID']),
            'username' => self::col($conn, $p.'Author', ['Username', 'User_name']),
            'email' => self::col($conn, $p.'Author', ['Author_email', 'Email', 'Author_Email']),
            'joined' => self::col($conn, $p.'Author', ['Join_date', 'Joined_date', 'Date_joined']),

            // tblForum
            'forum_id' => self::col($conn, $p.'Forum', ['Forum_ID']),
            'forum_name' => self::col($conn, $p.'Forum', ['Forum_name', 'Forum_Name', 'Name']),
            'forum_desc' => self::col($conn, $p.'Forum', ['Forum_description', 'Forum_Description', 'Description']),
            'forum_order' => self::col($conn, $p.'Forum', ['Forum_order', 'Sort_order', 'Forum_Order']),

            // tblTopic  (= a discussion)
            'topic_id' => self::col($conn, $p.'Topic', ['Topic_ID']),
            'topic_forum' => self::col($conn, $p.'Topic', ['Forum_ID']),
            'topic_subj' => self::col($conn, $p.'Topic', ['Subject', 'Topic_subject']),
            'topic_author' => self::col($conn, $p.'Topic', ['Author_ID']),
            'topic_date' => self::col($conn, $p.'Topic', ['Start_date', 'Topic_date', 'Date']),
            'topic_locked' => self::col($conn, $p.'Topic', ['Locked', 'Is_locked']),
            'topic_sticky' => self::col($conn, $p.'Topic', ['Priority', 'Sticky', 'Is_sticky']),
            // Held for a moderator (Web Wiz's approval queue): not on the board.
            'topic_hide' => self::col($conn, $p.'Topic', ['Hide']),

            // tblThread (= a post)
            'thread_id' => self::col($conn, $p.'Thread', ['Thread_ID', 'Message_ID']),
            'thread_topic' => self::col($conn, $p.'Thread', ['Topic_ID']),
            'thread_author' => self::col($conn, $p.'Thread', ['Author_ID']),
            'thread_body' => self::col($conn, $p.'Thread', ['Message', 'Thread_message', 'Body', 'Post']),
            'thread_date' => self::col($conn, $p.'Thread', ['Message_date', 'Thread_date', 'Date', 'Post_date']),
            'thread_hide' => self::col($conn, $p.'Thread', ['Hide']),
        ];

        // Without these there is nothing to import; the rest degrade to null.
        $required = [
            'author_id', 'username', 'forum_id', 'forum_name',
            'topic_id', 'topic_forum', 'topic_subj',
            'thread_id', 'thread_topic', 'thread_body',
        ];

        $missing = array_values(array_filter($required, fn ($k) => $cols[$k] === null));

        return ['cols' => $cols, 'missing' => $missing];
    }

    /** Rows the board shows: `Hide` off (or never set, on a version without the queue). */
    private static function shown(Builder $q, ?string $hide): Builder
    {
        return $hide ? $q->where(fn ($w) => $w->where($hide, 0)->orWhereNull($hide)) : $q;
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();

        foreach (['Author', 'Forum', 'Topic', 'Thread'] as $req) {
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.webwiz', ['table' => $p.$req]));
            }
        }

        ['cols' => $cols, 'missing' => $missing] = self::schema($cfg);

        if ($missing !== []) {
            throw new \RuntimeException(Src::t('not_source.webwiz_columns', ['columns' => implode(', ', $missing)]));
        }

        return [
            'ok' => true,
            'counts' => [
                'users' => (int) $conn->table($p.'Author')->count(),
                'categories' => (int) $conn->table($p.'Forum')->count(),
                'topics' => (int) $conn->table($p.'Topic')->count(),
                'posts' => (int) $conn->table($p.'Thread')->count(),
            ],
            // Surfaced so the admin can sanity-check the mapping before running.
            'resolved_columns' => array_filter($cols),
        ];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = self::prefix($cfg);
        $hasTags = Dst::hasTags();
        $c = self::schema($cfg)['cols'];

        return array_merge([
            new Phase(
                'tags',
                'forums',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'Forum')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags, $c) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p.'Forum')
                        ->where($c['forum_id'], '>', (int) $cursor)
                        ->orderBy($c['forum_id'])->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $f) {
                        $id = (int) $f->{$c['forum_id']};
                        $cursor = $id;
                        $name = (string) ($f->{$c['forum_name']} ?? '') ?: 'Forum';
                        $map[$id] = Dst::tag(
                            $name,
                            Src::tagSlug($name, $id),
                            $c['forum_desc'] ? ($f->{$c['forum_desc']} ?? null) : null,
                            null,
                            $c['forum_order'] ? (int) ($f->{$c['forum_order']} ?? 0) : 0
                        );
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'Author')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $c) {
                    $rows = $ctx->src()->table($p.'Author')
                        ->where($c['author_id'], '>', (int) $cursor)
                        ->orderBy($c['author_id'])->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $id = (int) $u->{$c['author_id']};
                        $cursor = $id;
                        $email = $c['email'] ? trim((string) ($u->{$c['email']} ?? '')) : '';
                        if ($email === '') {
                            $skip++;

                            continue;
                        }

                        try {
                            $map[$id] = Dst::user(
                                Src::username($u->{$c['username']} ?? null, $id),
                                $email,
                                null,
                                // 🚨 Never null: Dst::user() would throw, and the member was skipped without a word.
                                Src::ts($c['joined'] ? ($u->{$c['joined']} ?? null) : null),
                                display: $u->{$c['username']} ?? null
                            );
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
                fn () => (int) self::shown(Src::connect($cfg)->table($p.'Topic'), $c['topic_hide'])->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags, $c) {
                    $rows = self::shown($ctx->src()->table($p.'Topic'), $c['topic_hide'])
                        ->where($c['topic_id'], '>', (int) $cursor)
                        ->orderBy($c['topic_id'])->limit($limit)->get();
                    $userMap = $c['topic_author'] ? $ctx->mapGet('user', $rows->pluck($c['topic_author'])->all()) : [];
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck($c['topic_forum'])->all()) : [];
                    $map = [];
                    $n = 0;
                    $known = $ctx->known('topic', $rows->pluck($c['topic_id']));
                    foreach ($rows as $t) {
                        $id = (int) $t->{$c['topic_id']};
                        $cursor = $id;
                        if (isset($known[(string) $id])) {
                            continue;
                        }

                        /*
                         * 🚨 `Moved_ID` is NOT a pointer row. Web Wiz moves a
                         * topic by changing its `Forum_ID` and keeping the forum
                         * it came from in `Moved_ID`, so the old forum can list
                         * it as "moved". Skipping those dropped every topic a
                         * moderator had ever moved, with all of its posts.
                         */
                        $did = Dst::discussion(
                            (string) ($t->{$c['topic_subj']} ?? '') ?: 'Untitled',
                            $c['topic_author'] ? ($userMap[(string) $t->{$c['topic_author']}] ?? null) : null,
                            Src::ts($c['topic_date'] ? ($t->{$c['topic_date']} ?? null) : null),
                            $c['topic_sticky'] && (int) ($t->{$c['topic_sticky']} ?? 0) > 0,
                            $c['topic_locked'] && (int) ($t->{$c['topic_locked']} ?? 0) !== 0,
                        );
                        $map[$id] = $did;
                        if ($hasTags && isset($tagMap[(string) $t->{$c['topic_forum']}])) {
                            Dst::attachTag($did, $tagMap[(string) $t->{$c['topic_forum']}]);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n]];
                }
            ),

            ...Phases::posts(
                $p.'Thread',
                $c['thread_topic'],
                $c['thread_id'],
                fn () => (int) self::shown(Src::connect($cfg)->table($p.'Thread'), $c['thread_hide'])->count(),
                fn ($post) => [
                    'tid' => (int) $post->{$c['thread_topic']},
                    'pid' => (int) $post->{$c['thread_id']},
                    'uid' => $c['thread_author'] ? $post->{$c['thread_author']} : null,
                    'html' => self::body((string) ($post->{$c['thread_body']} ?? '')),
                    'at' => Src::ts($c['thread_date'] ? ($post->{$c['thread_date']} ?? null) : null),
                    'ok' => true,
                ],
                fn (Builder $q) => self::shown($q, $c['thread_hide']),
            ),
        ], Phases::tail());
    }

    /**
     * Web Wiz saves a post as the HTML its editor produced, not as BBCode, so
     * escaping it as text put every <br /> and <strong> on the page. Older
     * rows can still be BBCode.
     */
    /**
     * `tblPMMessage` holds each message once, between two members: `From_ID`
     * sent it to `Author_ID`. `Inbox` / `Outbox` say whether the recipient and
     * the sender still have it (a deleted copy is switched off), `Read_Post`
     * whether the recipient has read it. Column names vary by version, so each
     * is looked up; the title is `PM_Tittle` (sic) on most installs.
     */
    public static function privateMessages(array $cfg): array
    {
        $t = self::prefix($cfg).'PMMessage';
        $cols = function ($conn) use ($t) {
            if (! $conn->getSchemaBuilder()->hasTable($t)) {
                return null;
            }
            $c = [
                'id' => self::col($conn, $t, ['PM_ID']),
                'to' => self::col($conn, $t, ['Author_ID']),
                'from' => self::col($conn, $t, ['From_ID']),
                'title' => self::col($conn, $t, ['PM_Tittle', 'PM_Title', 'Title']),
                'body' => self::col($conn, $t, ['PM_Message', 'Message']),
                'date' => self::col($conn, $t, ['PM_Message_Date', 'PM_Message_date', 'Message_date']),
                'read' => self::col($conn, $t, ['Read_Post']),
                'inbox' => self::col($conn, $t, ['Inbox']),
                'outbox' => self::col($conn, $t, ['Outbox']),
            ];

            return $c['id'] && $c['to'] && $c['from'] && $c['body'] ? $c : null;
        };

        return [
            'count' => function () use ($cfg, $t, $cols) {
                $conn = Src::connect($cfg);

                return $cols($conn) ? (int) $conn->table($t)->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($t, $cols) {
                $conn = $ctx->src();
                if (! $c = $cols($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($t)->where($c['id'], '>', $after)->orderBy($c['id'])->limit($limit)->get();
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->{$c['id']};
                    $to = $m->{$c['to']};
                    $from = $m->{$c['from']};
                    $keep = [];
                    if (! $c['inbox'] || (int) $m->{$c['inbox']}) {
                        $keep[] = $to;
                    }
                    if (! $c['outbox'] || (int) $m->{$c['outbox']}) {
                        $keep[] = $from;
                    }
                    $out[] = [
                        'id' => (string) $after,
                        'conv' => '',
                        'title' => $c['title'] ? (string) $m->{$c['title']} : '',
                        'uid' => $from,
                        'people' => [$to],
                        'keep' => $keep,
                        'unread' => $c['read'] && ! (int) $m->{$c['read']} ? [$to] : [],
                        'html' => self::body((string) $m->{$c['body']}),
                        'at' => Src::ts($c['date'] ? $m->{$c['date']} : null),
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    private static function body(string $body): string
    {
        // Web Wiz's editor stores HTML, and its forum codes ([QUOTE], [TUBE]…) sit inside it.
        return Bbcode::toHtml($body, preg_match('#<(?:br|p|div|span|strong|b|i|em|u|a|img|font|blockquote|ul|ol|li|table)\b#i', $body) ? ['html' => 'raw'] : []);
    }

    /**
     * `Poll` and `PollChoice`, with `Topic.Poll_ID` pointing at the poll.
     * Web Wiz keeps only each choice's total, never who voted, so every vote
     * comes across without a voter. Its "multiple votes" lets one member vote
     * again rather than pick several, which fof/polls has no setting for: each
     * poll is one choice.
     */
    public static function polls(array $cfg): array
    {
        $p = self::prefix($cfg);
        $cols = function (ConnectionInterface $conn) use ($p): ?array {
            $c = [
                'poll_id' => self::col($conn, $p.'Poll', ['Poll_ID']),
                'question' => self::col($conn, $p.'Poll', ['Poll_question', 'Question']),
                'choice_id' => self::col($conn, $p.'PollChoice', ['Choice_ID']),
                'choice_poll' => self::col($conn, $p.'PollChoice', ['Poll_ID']),
                'choice' => self::col($conn, $p.'PollChoice', ['Choice']),
                'votes' => self::col($conn, $p.'PollChoice', ['Votes']),
                'topic_poll' => self::col($conn, $p.'Topic', ['Poll_ID']),
                'topic_id' => self::col($conn, $p.'Topic', ['Topic_ID']),
                'topic_author' => self::col($conn, $p.'Topic', ['Author_ID']),
                'topic_date' => self::col($conn, $p.'Topic', ['Start_date', 'Topic_date', 'Date']),
            ];
            foreach (['poll_id', 'question', 'choice_id', 'choice_poll', 'choice', 'topic_poll', 'topic_id'] as $k) {
                if ($c[$k] === null) {
                    return null;
                }
            }

            return $c;
        };

        return [
            'count' => function () use ($cfg, $p, $cols) {
                $conn = Src::connect($cfg);

                return $cols($conn) ? (int) $conn->table($p.'Poll')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $cols) {
                $conn = $ctx->src();
                if (! $c = $cols($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'polls' => []];
                }
                [$rows, $cursor, $done, $n] = Polls::page($conn->table($p.'Poll'), $c['poll_id'], $after, $limit, $ctx);
                $ids = $rows->pluck($c['poll_id'])->all();
                $topics = $ids ? $conn->table($p.'Topic')->whereIn($c['topic_poll'], $ids)->get()->keyBy($c['topic_poll']) : collect();
                $options = [];
                foreach ($ids ? $conn->table($p.'PollChoice')->whereIn($c['choice_poll'], $ids)->orderBy($c['choice_id'])->get() : [] as $o) {
                    $options[$o->{$c['choice_poll']}][(string) $o->{$c['choice_id']}] = ['text' => Polls::plain(self::body((string) $o->{$c['choice']})), 'count' => $c['votes'] ? (int) $o->{$c['votes']} : 0];
                }

                $out = [];
                foreach ($rows as $poll) {
                    $id = $poll->{$c['poll_id']};
                    $topic = $topics->get($id);
                    $out[] = [
                        'id' => (string) $id,
                        'topic' => $topic ? (string) $topic->{$c['topic_id']} : null,
                        'question' => Polls::plain(self::body((string) $poll->{$c['question']})),
                        'uid' => $topic && $c['topic_author'] ? $topic->{$c['topic_author']} : null,
                        'at' => Src::ts($topic && $c['topic_date'] ? $topic->{$c['topic_date']} : null),
                        'ends' => null,
                        'max' => 1,
                        'public' => false,
                        'change' => false,
                        'options' => $options[$id] ?? [],
                        'votes' => [],
                    ];
                }

                return ['cursor' => $cursor, 'rows' => $n, 'done' => $done, 'polls' => $out];
            },
        ];
    }
}
