<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Web Wiz Forums (ASP / SQL Server) → Flarum.
 *   tblForum → tags · tblAuthor → users · tblTopic → discussions · tblThread → posts
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
    private static function col($conn, string $table, array $candidates): ?string
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
        $p    = self::prefix($cfg);

        $cols = [
            // tblAuthor
            'author_id'    => self::col($conn, $p . 'Author', ['Author_ID']),
            'username'     => self::col($conn, $p . 'Author', ['Username', 'User_name']),
            'email'        => self::col($conn, $p . 'Author', ['Author_email', 'Email', 'Author_Email']),
            'joined'       => self::col($conn, $p . 'Author', ['Join_date', 'Joined_date', 'Date_joined']),

            // tblForum
            'forum_id'     => self::col($conn, $p . 'Forum', ['Forum_ID']),
            'forum_name'   => self::col($conn, $p . 'Forum', ['Forum_name', 'Forum_Name', 'Name']),
            'forum_desc'   => self::col($conn, $p . 'Forum', ['Forum_description', 'Forum_Description', 'Description']),
            'forum_order'  => self::col($conn, $p . 'Forum', ['Forum_order', 'Sort_order', 'Forum_Order']),

            // tblTopic  (= a discussion)
            'topic_id'     => self::col($conn, $p . 'Topic', ['Topic_ID']),
            'topic_forum'  => self::col($conn, $p . 'Topic', ['Forum_ID']),
            'topic_subj'   => self::col($conn, $p . 'Topic', ['Subject', 'Topic_subject']),
            'topic_author' => self::col($conn, $p . 'Topic', ['Author_ID']),
            'topic_date'   => self::col($conn, $p . 'Topic', ['Start_date', 'Topic_date', 'Date']),
            'topic_locked' => self::col($conn, $p . 'Topic', ['Locked', 'Is_locked']),
            'topic_sticky' => self::col($conn, $p . 'Topic', ['Priority', 'Sticky', 'Is_sticky']),
            'topic_moved'  => self::col($conn, $p . 'Topic', ['Moved_ID']),

            // tblThread (= a post)
            'thread_id'    => self::col($conn, $p . 'Thread', ['Thread_ID', 'Message_ID']),
            'thread_topic' => self::col($conn, $p . 'Thread', ['Topic_ID']),
            'thread_author' => self::col($conn, $p . 'Thread', ['Author_ID']),
            'thread_body'  => self::col($conn, $p . 'Thread', ['Message', 'Thread_message', 'Body', 'Post']),
            'thread_date'  => self::col($conn, $p . 'Thread', ['Message_date', 'Thread_date', 'Date', 'Post_date']),
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

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p    = self::prefix($cfg);
        $sb   = $conn->getSchemaBuilder();

        foreach (['Author', 'Forum', 'Topic', 'Thread'] as $req) {
            if (! $sb->hasTable($p . $req)) {
                throw new \RuntimeException(
                    "This doesn't look like a Web Wiz Forums database (missing “{$p}{$req}”). Check the table prefix — Web Wiz ships tables as tblAuthor, tblForum, tblTopic, tblThread."
                );
            }
        }

        ['cols' => $cols, 'missing' => $missing] = self::schema($cfg);

        if ($missing !== []) {
            throw new \RuntimeException(
                'Found the Web Wiz tables, but could not identify these columns: ' . implode(', ', $missing)
                . '. This is likely a Web Wiz version with a different schema — send the output of a column listing for tblAuthor/tblForum/tblTopic/tblThread so the mapping can be extended.'
            );
        }

        return [
            'ok' => true,
            'counts' => [
                'users' => (int) $conn->table($p . 'Author')->count(),
                'categories' => (int) $conn->table($p . 'Forum')->count(),
                'topics' => (int) $conn->table($p . 'Topic')->count(),
                'posts' => (int) $conn->table($p . 'Thread')->count(),
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
            new Phase('tags', 'Importing forums…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p . 'Forum')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags, $c) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p . 'Forum')
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

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table($p . 'Author')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $c) {
                    $rows = $ctx->src()->table($p . 'Author')
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
                                $c['joined'] ? Src::ts($u->{$c['joined']} ?? null) : null
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

            new Phase('topics', 'Importing topics…',
                fn () => (int) Src::connect($cfg)->table($p . 'Topic')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags, $c) {
                    $rows = $ctx->src()->table($p . 'Topic')
                        ->where($c['topic_id'], '>', (int) $cursor)
                        ->orderBy($c['topic_id'])->limit($limit)->get();
                    $userMap = $c['topic_author'] ? $ctx->mapGet('user', $rows->pluck($c['topic_author'])->all()) : [];
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck($c['topic_forum'])->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $id = (int) $t->{$c['topic_id']};
                        $cursor = $id;

                        // A "moved" topic is a pointer left behind when a topic
                        // was relocated — its content lives under the target id,
                        // so importing it would duplicate the discussion.
                        if ($c['topic_moved'] && (int) ($t->{$c['topic_moved']} ?? 0) > 0) {
                            continue;
                        }

                        $did = Dst::discussion(
                            (string) ($t->{$c['topic_subj']} ?? '') ?: 'Untitled',
                            $c['topic_author'] ? ($userMap[(string) $t->{$c['topic_author']}] ?? null) : null,
                            $c['topic_date'] ? Src::ts($t->{$c['topic_date']} ?? null) : null,
                            $c['topic_sticky'] ? ((int) ($t->{$c['topic_sticky']} ?? 0) > 0) : false
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

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table($p . 'Thread')->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table($p . 'Thread')
                        ->where(fn ($q) => $q->where($c['thread_topic'], '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where($c['thread_topic'], (int) $cur['tid'])->where($c['thread_id'], '>', (int) $cur['pid'])))
                        ->orderBy($c['thread_topic'])->orderBy($c['thread_id'])->limit($lim)->get(),
                    fn ($post) => [
                        'tid' => (int) $post->{$c['thread_topic']},
                        'pid' => (int) $post->{$c['thread_id']},
                        'uid' => $c['thread_author'] ? $post->{$c['thread_author']} : null,
                        'html' => Bbcode::toHtml((string) ($post->{$c['thread_body']} ?? '')),
                        'at' => $c['thread_date'] ? Src::ts($post->{$c['thread_date']} ?? null) : null,
                        'ok' => true,
                    ]
                )
            ),
        ], Phases::tail());
    }
}
