<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Vanilla Forums → Flarum.
 *   GDN_Category → tags · GDN_User → users · GDN_Discussion → discussions (Body = post #1) · GDN_Comment → replies
 * Each body carries a per-row Format (Html / Markdown / BBCode / Text / Rich Quill JSON),
 * so the converter dispatches on it. Default table prefix is `GDN_`.
 */
class VanillaImporter
{
    private static function prefix(array $cfg): string
    {
        return trim((string) ($cfg['prefix'] ?? 'GDN_')) ?: 'GDN_';
    }

    public static function test(array $cfg): array
    {
        $conn = Src::connect($cfg);
        $p = self::prefix($cfg);
        $sb = $conn->getSchemaBuilder();
        foreach (['User', 'Category', 'Discussion', 'Comment'] as $req) {
            if (! $sb->hasTable($p . $req)) {
                throw new \RuntimeException("This doesn't look like a Vanilla database (missing “{$p}{$req}”). Check the table prefix.");
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p . 'User')->count(),
            'categories' => (int) $conn->table($p . 'Category')->where('CategoryID', '>', 0)->count(),
            'topics' => (int) $conn->table($p . 'Discussion')->count(),
            'posts' => (int) $conn->table($p . 'Comment')->count(),
        ]];
    }

    /** @return Phase[] */
    public static function phases(array $cfg): array
    {
        $p = self::prefix($cfg);
        $hasTags = Dst::hasTags();

        return array_merge([
            new Phase('tags', 'Importing categories…',
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p . 'Category')->where('CategoryID', '>', 0)->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p . 'Category')->where('CategoryID', '>', max(0, (int) $cursor))->orderBy('CategoryID')->limit($limit)->get();
                    $map = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->CategoryID;
                        $name = trim((string) ($c->Name ?? '')) ?: ('Category ' . $c->CategoryID);
                        $slug = trim((string) ($c->UrlCode ?? '')) !== '' ? (\Illuminate\Support\Str::slug($c->UrlCode) . '-' . $c->CategoryID) : Src::tagSlug($name, (int) $c->CategoryID);
                        $map[$c->CategoryID] = Dst::tag($name, $slug, $c->Description ?? null, null, (int) ($c->Sort ?? 0));
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase('users', 'Importing members…',
                fn () => (int) Src::connect($cfg)->table($p . 'User')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    $hasDeleted = $conn->getSchemaBuilder()->hasColumn($p . 'User', 'Deleted');
                    $rows = $conn->table($p . 'User')->where('UserID', '>', (int) $cursor)->orderBy('UserID')->limit($limit)->get();
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->UserID;
                        $email = trim((string) ($u->Email ?? ''));
                        if ($email === '' || ($hasDeleted && (int) ($u->Deleted ?? 0) === 1)) {
                            $skip++;

                            continue;
                        }
                        try {
                            $map[$u->UserID] = Dst::user(Src::username($u->Name ?? null, (int) $u->UserID), $email, $u->Password ?? null, Src::ts($u->DateInserted ?? null));
                            $n++;
                        } catch (\Throwable) {
                            $skip++;
                        }
                    }
                    $ctx->mapPut('user', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['users' => $n, 'skipped' => $skip]];
                }
            ),

            // Discussion → topic; its own Body is the first post (#1).
            new Phase('topics', 'Importing discussions…',
                fn () => (int) Src::connect($cfg)->table($p . 'Discussion')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    $rows = $ctx->src()->table($p . 'Discussion')->where('DiscussionID', '>', (int) $cursor)->orderBy('DiscussionID')->limit($limit)->get();
                    $userMap = $ctx->mapGet('user', $rows->pluck('InsertUserID')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('CategoryID')->all()) : [];
                    $map = [];
                    $n = 0;
                    foreach ($rows as $d) {
                        $cursor = $d->DiscussionID;
                        $title = trim((string) ($d->Name ?? '')) ?: 'Untitled';
                        $created = Src::ts($d->DateInserted ?? null);
                        $uid = $userMap[(string) $d->InsertUserID] ?? null;
                        $did = Dst::discussion($title, $uid, $created, (int) ($d->Announce ?? 0) > 0, (int) ($d->Closed ?? 0) === 1);
                        $map[$d->DiscussionID] = $did;
                        if ($hasTags && isset($tagMap[(string) $d->CategoryID])) {
                            Dst::attachTag($did, $tagMap[(string) $d->CategoryID]);
                        }
                        Dst::post($did, 1, $uid, self::body($d->Body ?? '', $d->Format ?? '') ?: '<p></p>', $created);
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['topics' => $n, 'posts' => $n]];
                }
            ),

            // Comments → replies, continuing each discussion's numbering after #1.
            new Phase('posts', 'Importing comments…',
                fn () => (int) Src::connect($cfg)->table($p . 'Comment')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $cur = is_array($cursor) ? $cursor : ['did' => 0, 'cid' => 0, 'carry' => null];
                    $carry = $cur['carry'] ?? null;
                    $rows = $ctx->src()->table($p . 'Comment')
                        ->where(fn ($q) => $q->where('DiscussionID', '>', (int) $cur['did'])
                            ->orWhere(fn ($q2) => $q2->where('DiscussionID', (int) $cur['did'])->where('CommentID', '>', (int) $cur['cid'])))
                        ->orderBy('DiscussionID')->orderBy('CommentID')->limit($limit)->get();
                    $topicMap = $ctx->mapGet('topic', $rows->pluck('DiscussionID')->all());
                    $userMap = $ctx->mapGet('user', $rows->pluck('InsertUserID')->all());
                    $db = Dst::db();
                    $n = 0;
                    foreach ($rows as $c) {
                        $cur['did'] = (int) $c->DiscussionID;
                        $cur['cid'] = (int) $c->CommentID;
                        $did = $topicMap[(string) $c->DiscussionID] ?? null;
                        if (! $did) {
                            continue;
                        }
                        if (! $carry || (int) $carry['did'] !== (int) $did) {
                            if ($carry && ! empty($carry['did'])) {
                                Dst::finalizeDiscussion((int) $carry['did']);
                            }
                            $carry = ['did' => (int) $did, 'num' => (int) ($db->table('posts')->where('discussion_id', $did)->max('number') ?? 0)];
                        }
                        $created = Src::ts($c->DateInserted ?? null);
                        try {
                            Dst::post($did, ++$carry['num'], $userMap[(string) $c->InsertUserID] ?? null, self::body($c->Body ?? '', $c->Format ?? '') ?: '<p></p>', $created);
                            $n++;
                        } catch (\Throwable) {
                            $carry['num']--;
                        }
                    }
                    $done = count($rows) < $limit;
                    if ($done && $carry && ! empty($carry['did'])) {
                        Dst::finalizeDiscussion((int) $carry['did']);
                    }
                    $cur['carry'] = $done ? null : $carry;

                    return ['cursor' => $cur, 'processed' => count($rows), 'done' => $done, 'summary' => ['posts' => $n]];
                }
            ),
        ], Phases::tail());
    }

    /** Convert a Vanilla body according to its per-row Format. */
    private static function body(?string $body, ?string $format): string
    {
        $body = (string) $body;
        if (trim($body) === '') {
            return '';
        }
        switch (strtolower(trim((string) $format))) {
            case 'html':
            case 'wysiwyg':
                return Src::sanitizeHtml($body);
            case 'bbcode':
                return Bbcode::toHtml($body);
            case 'markdown':
                return Src::markdown($body);
            case 'rich':
            case 'rich2':
                return self::richToHtml($body);
            default: // 'text' and anything unknown
                return self::textToHtml($body);
        }
    }

    private static function textToHtml(string $text): string
    {
        $out = '';
        foreach (preg_split('/\n{2,}/', trim($text)) as $block) {
            $block = trim((string) $block);
            if ($block !== '') {
                $out .= '<p>' . nl2br(htmlspecialchars($block, ENT_QUOTES), false) . '</p>';
            }
        }

        return $out;
    }

    /** Vanilla "Rich"/"Rich2" bodies are a Quill delta — pull out the text, safely. */
    private static function richToHtml(string $json): string
    {
        $data = json_decode($json, true);
        $ops = is_array($data) ? ($data['ops'] ?? $data) : null;
        if (! is_array($ops)) {
            return self::textToHtml($json);
        }
        $text = '';
        foreach ($ops as $op) {
            $insert = is_array($op) ? ($op['insert'] ?? '') : '';
            if (is_string($insert)) {
                $text .= $insert;
            } elseif (is_array($insert) && isset($insert['url'])) {
                $text .= ' ' . $insert['url'] . ' ';
            }
        }

        return self::textToHtml($text);
    }
}
