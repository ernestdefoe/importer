<?php

namespace ErnestDefoe\Importer\Importers;

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

    private static function body(?string $body): string
    {
        $body = preg_replace('#<br\s*/?>#i', "\n", (string) $body) ?? (string) $body;

        return Bbcode::toHtml($body, ['escaped' => true]);
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['members', 'boards', 'topics', 'messages'] as $req) {
            if (! $sb->hasTable($p . $req)) {
                throw new \RuntimeException("This doesn't look like an SMF database (missing “{$p}{$req}”). Check the table prefix.");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p . 'members')->count(),
            'categories' => (int) $conn->table($p . 'boards')->count(),
            'topics' => (int) $conn->table($p . 'topics')->where('approved', 1)->count(),
            'posts' => (int) $conn->table($p . 'messages')->where('approved', 1)->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = self::prefix($cfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing boards…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p . 'boards')->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p . 'boards')->where('id_board', '>', (int) $cursor)->orderBy('id_board')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $b) {
                        $cursor = $b->id_board;
                        if (trim((string) ($b->redirect ?? '')) !== '') {
                            continue; // redirect board — no content
                        }
                        $name = self::decode($b->name ?: 'Board');
                        $map[$b->id_board] = Dst::tag($name, Src::tagSlug($name, (int) $b->id_board), self::decode((string) ($b->description ?? '')) ?: null, null, (int) ($b->board_order ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table($p . 'members')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $rows = $ctx->src()->table($p . 'members')->where('id_member', '>', (int) $cursor)->orderBy('id_member')->limit($limit)->get();
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
                        try {
                            $map[$u->id_member] = Dst::user(Src::username($name !== '' ? $name : null, (int) $u->id_member), $email, null, Src::ts($u->date_registered ?? null));
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
            new Phase('topics', 'Importing topics…',
                fn () => (int) Src::connect($cfg)->table($p . 'topics')->where('approved', 1)->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $conn = $ctx->src();
                    $rows = $conn->table($p . 'topics')->where('approved', 1)->where('id_redirect_topic', 0)
                        ->where('id_topic', '>', (int) $cursor)->orderBy('id_topic')->limit($limit)->get();
                    $firstMsgIds = $rows->pluck('id_first_msg')->filter()->all();
                    $firstMsg = [];
                    if ($firstMsgIds) {
                        foreach ($conn->table($p . 'messages')->whereIn('id_msg', $firstMsgIds)->get(['id_msg', 'subject', 'poster_time']) as $m) {
                            $firstMsg[(string) $m->id_msg] = $m;
                        }
                    }
                    $userMap = $ctx->mapGet('user', $rows->pluck('id_member_started')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('id_board')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $t) {
                        $cursor = $t->id_topic;
                        $fm = $firstMsg[(string) $t->id_first_msg] ?? null;
                        $title = self::decode((string) ($fm->subject ?? '')) ?: 'Untitled';
                        $did = Dst::discussion($title, $userMap[(string) $t->id_member_started] ?? null, Src::ts($fm->poster_time ?? null), (bool) ($t->is_sticky ?? false));
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

            new Phase('posts', 'Importing posts…',
                fn () => (int) Src::connect($cfg)->table($p . 'messages')->where('approved', 1)->count(),
                fn ($cursor, $limit, Ctx $ctx) => Phases::postsBatch($cursor, $limit, $ctx,
                    fn ($conn, $cur, $lim) => $conn->table($p . 'messages')->where('approved', 1)
                        ->where(fn ($q) => $q->where('id_topic', '>', (int) $cur['tid'])
                            ->orWhere(fn ($q2) => $q2->where('id_topic', (int) $cur['tid'])->where('id_msg', '>', (int) $cur['pid'])))
                        ->orderBy('id_topic')->orderBy('id_msg')->limit($lim)->get(),
                    fn ($m) => [
                        'tid' => (int) $m->id_topic, 'pid' => (int) $m->id_msg, 'uid' => $m->id_member,
                        'html' => self::body($m->body ?? ''), 'at' => Src::ts($m->poster_time ?? null), 'ok' => true,
                    ]
                )
            ),
        ], Phases::tail());
    }
}
