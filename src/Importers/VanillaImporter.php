<?php

namespace ErnestDefoe\Importer\Importers;

use ErnestDefoe\Importer\Markup\Html;
use ErnestDefoe\Importer\Markup\VanillaRich;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

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
            if (! $sb->hasTable($p.$req)) {
                throw new \RuntimeException(Src::t('not_source.vanilla', ['table' => $p.$req]));
            }
        }

        return ['ok' => true, 'counts' => [
            'users' => (int) $conn->table($p.'User')->count(),
            'categories' => (int) $conn->table($p.'Category')->where('CategoryID', '>', 0)->count(),
            'topics' => (int) $conn->table($p.'Discussion')->count(),
            'posts' => (int) $conn->table($p.'Comment')->count(),
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
                fn () => $hasTags ? (int) Src::connect($cfg)->table($p.'Category')->where('CategoryID', '>', 0)->count() : 0,
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags) {
                    if (! $hasTags) {
                        return ['cursor' => null, 'processed' => 0, 'done' => true, 'summary' => []];
                    }
                    $rows = $ctx->src()->table($p.'Category')->where('CategoryID', '>', max(0, (int) $cursor))->orderBy('CategoryID')->limit($limit)->get();
                    $open = self::openCategories($ctx->src(), $p, $rows->pluck('PermissionCategoryID')->filter(fn ($id) => (int) $id > 0)->unique()->values()->all());
                    $map = $slugs = [];
                    $n = 0;
                    foreach ($rows as $c) {
                        $cursor = $c->CategoryID;
                        $name = trim((string) ($c->Name ?? '')) ?: ('Category '.$c->CategoryID);
                        $code = trim((string) ($c->UrlCode ?? ''));
                        $slug = $code !== '' ? (\Illuminate\Support\Str::slug($code).'-'.$c->CategoryID) : Src::tagSlug($name, (int) $c->CategoryID);
                        // Custom permissions (PermissionCategoryID > 0, its own or inherited) that let neither guests nor members view it.
                        $perm = (int) ($c->PermissionCategoryID ?? -1);
                        $map[$c->CategoryID] = Dst::tag($name, $slug, $c->Description ?? null, null, (int) ($c->Sort ?? 0), restricted: $open !== null && $perm > 0 && ! isset($open[$perm]));
                        if ($code !== '') {
                            $slugs[$code] = $map[$c->CategoryID]; // /categories/{UrlCode}
                        }
                        $n++;
                    }
                    $ctx->mapPut('tag', $map);
                    $ctx->mapPut('tag-slug', $slugs);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => ['categories' => $n]];
                }
            ),

            new Phase(
                'users',
                'members',
                fn () => (int) Src::connect($cfg)->table($p.'User')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p) {
                    $conn = $ctx->src();
                    $rows = $conn->table($p.'User')->where('UserID', '>', (int) $cursor)->orderBy('UserID')->limit($limit)->get();
                    /*
                     * A deleted account comes along only if it wrote something,
                     * so its posts keep their author; it cannot sign in here
                     * either. Who wrote something: one lookup for the batch.
                     */
                    $deleted = $rows->filter(fn ($u) => (int) ($u->Deleted ?? 0) === 1)->pluck('UserID')->all();
                    $wrote = $deleted ? array_flip(array_map('strval', array_merge(
                        $conn->table($p.'Discussion')->whereIn('InsertUserID', $deleted)->distinct()->pluck('InsertUserID')->all(),
                        $conn->table($p.'Comment')->whereIn('InsertUserID', $deleted)->distinct()->pluck('InsertUserID')->all(),
                    ))) : [];
                    $map = [];
                    $n = $skip = 0;
                    foreach ($rows as $u) {
                        $cursor = $u->UserID;
                        $email = trim((string) ($u->Email ?? ''));
                        $gone = (int) ($u->Deleted ?? 0) === 1;
                        if ($email === '' || ($gone && ! isset($wrote[(string) $u->UserID]))) {
                            $skip++;

                            continue;
                        }

                        try {
                            $map[$u->UserID] = Dst::user(
                                Src::username($u->Name ?? null, (int) $u->UserID), $email, $u->Password ?? null, Src::ts($u->DateInserted ?? null), null, self::legacy($u),
                                // `Confirmed` 0: never confirmed the address. `Banned` is a bitmask (manual, IP, warnings): any bit = banned, for good.
                                confirmed: (int) ($u->Confirmed ?? 1) !== 0,
                                suspendedUntil: $gone || (int) ($u->Banned ?? 0) !== 0 ? Carbon::create(2038, 1, 1) : null,
                                display: $u->Name ?? null,
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

            // Discussion → topic; its own Body is the first post (#1).
            new Phase(
                'topics',
                'discussions',
                fn () => (int) Src::connect($cfg)->table($p.'Discussion')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $hasTags, $cfg) {
                    $rows = $ctx->src()->table($p.'Discussion')->where('DiscussionID', '>', (int) $cursor)->orderBy('DiscussionID')->limit($limit)->get();
                    /*
                     * 🚨 The comments phase finalises the discussions it writes
                     * to, so one with no comments never was: no first or last
                     * post, a comment count of 0. Those (and only those, so none
                     * is finalised twice) are finalised here, found with one
                     * query for the batch.
                     */
                    $replied = $rows->isNotEmpty() ? array_flip(array_map('strval', $ctx->src()->table($p.'Comment')
                        ->whereIn('DiscussionID', $rows->pluck('DiscussionID')->all())->distinct()->pluck('DiscussionID')->all())) : [];
                    $userMap = $ctx->mapGet('user', $rows->pluck('InsertUserID')->all());
                    $tagMap = $hasTags ? $ctx->mapGet('tag', $rows->pluck('CategoryID')->all()) : [];
                    $files = Attachments::batch(self::attachments($cfg), $ctx, $rows->map(fn ($d) => 'd'.$d->DiscussionID)->all());
                    $bodies = [];
                    foreach ($rows as $d) {
                        $bodies['d'.$d->DiscussionID] = self::body($d->Body ?? '', $d->Format ?? '') ?: '<p></p>';
                    }
                    $bodies = $files->finish($bodies);
                    Dst::prepare($ctx, $bodies);
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
                        $files->link('d'.$d->DiscussionID, Dst::post($did, 1, $uid, $bodies['d'.$d->DiscussionID], $created));
                        if (! isset($replied[(string) $d->DiscussionID])) {
                            Dst::finalizeDiscussion($did);
                        }
                        $n++;
                    }
                    $ctx->mapPut('topic', $map);

                    return ['cursor' => (int) $cursor, 'processed' => count($rows), 'done' => count($rows) < $limit, 'summary' => $files->done(['topics' => $n, 'posts' => $n])];
                }
            ),

            // Comments → replies, continuing each discussion's numbering after #1.
            new Phase(
                'posts',
                'comments',
                fn () => (int) Src::connect($cfg)->table($p.'Comment')->count(),
                function ($cursor, $limit, Ctx $ctx) use ($p, $cfg) {
                    $cur = is_array($cursor) ? $cursor : ['did' => 0, 'cid' => 0, 'carry' => null];
                    $carry = $cur['carry'] ?? null;
                    $rows = $ctx->src()->table($p.'Comment')
                        ->where(fn ($q) => $q->where('DiscussionID', '>', (int) $cur['did'])
                            ->orWhere(fn ($q2) => $q2->where('DiscussionID', (int) $cur['did'])->where('CommentID', '>', (int) $cur['cid'])))
                        ->orderBy('DiscussionID')->orderBy('CommentID')->limit($limit)->get();
                    $topicMap = $ctx->mapGet('topic', $rows->pluck('DiscussionID')->all());
                    $userMap = $ctx->mapGet('user', $rows->pluck('InsertUserID')->all());
                    $live = $rows->filter(fn ($c) => isset($topicMap[(string) $c->DiscussionID]));
                    $files = Attachments::batch(self::attachments($cfg), $ctx, $live->map(fn ($c) => 'c'.$c->CommentID)->all());
                    $bodies = [];
                    foreach ($live as $c) {
                        $bodies['c'.$c->CommentID] = self::body($c->Body ?? '', $c->Format ?? '') ?: '<p></p>';
                    }
                    $bodies = $files->finish($bodies);
                    Dst::prepare($ctx, $bodies);
                    // Where each discussion's numbering stands, in one grouped query for the batch.
                    $dids = array_values(array_unique($topicMap));
                    $next = $dids ? Dst::db()->table('posts')->whereIn('discussion_id', $dids)->groupBy('discussion_id')
                        ->selectRaw('discussion_id, MAX(number) n')->pluck('n', 'discussion_id')->all() : [];
                    $ids = [];
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
                            $carry = ['did' => (int) $did, 'num' => (int) ($next[$did] ?? 0)];
                        }
                        $created = Src::ts($c->DateInserted ?? null);

                        try {
                            $ids[$c->CommentID] = Dst::post($did, ++$carry['num'], $userMap[(string) $c->InsertUserID] ?? null, $bodies['c'.$c->CommentID], $created);
                            $files->link('c'.$c->CommentID, $ids[$c->CommentID]);
                            Dst::ref('post', (string) $c->CommentID, $ids[$c->CommentID]);
                            $n++;
                        } catch (\Throwable) {
                            $carry['num']--;
                        }
                    }
                    // /discussion/comment/{CommentID} redirects to its post.
                    $ctx->mapPut('post', $ids);
                    $done = count($rows) < $limit;
                    if ($done && $carry && ! empty($carry['did'])) {
                        Dst::finalizeDiscussion((int) $carry['did']);
                    }
                    $cur['carry'] = $done ? null : $carry;

                    return ['cursor' => $cur, 'processed' => count($rows), 'done' => $done, 'summary' => $files->done(['posts' => $n])];
                }
            ),
        ], Phases::tail());
    }

    /**
     * Groups are roles: `Role` and `UserRole`. `Role.Type` says what each is
     * (older boards have none, and keep Vanilla's stock ids: 2 guest, 3
     * unconfirmed, 4 applicant, 8 member, 16 administrator, 32 moderator).
     */
    public static function groups(array $cfg): array
    {
        $p = self::prefix($cfg);
        $types = ['guest' => 'guest', 'member' => 'member', 'unconfirmed' => 'pending', 'applicant' => 'pending', 'administrator' => 'admin', 'moderator' => 'mod'];
        $stock = [2 => 'guest', 3 => 'pending', 4 => 'pending', 8 => 'member', 16 => 'admin', 32 => 'mod'];

        return [
            'needs' => [$p.'Role', $p.'UserRole'],
            'groups' => fn (Ctx $ctx) => $ctx->src()->table($p.'Role')->orderBy('RoleID')->get()->map(fn ($r) => [
                'id' => (string) $r->RoleID, 'name' => (string) $r->Name,
                'role' => property_exists($r, 'Type') ? ($types[strtolower((string) $r->Type)] ?? null) : ($stock[(int) $r->RoleID] ?? null),
            ])->all(),
            'members' => fn ($after, int $limit, Ctx $ctx) => Groups::pivot($ctx->src()->table($p.'User'), 'UserID', $after, $limit,
                fn (array $ids) => $ctx->src()->table($p.'UserRole')->whereIn('UserID', $ids)->get()->map(fn ($r) => [$r->UserID, $r->RoleID])),
        ];
    }

    /**
     * Profile photos: `User.Photo`, an address or a path under uploads/
     * ("userpics/12/abc.jpg", whose full-size copy is "p" + the name beside
     * it). Birthdays: `DateOfBirth`.
     */
    public static function profiles(array $cfg): array
    {
        $p = self::prefix($cfg);

        return [
            'count' => fn () => (int) Src::connect($cfg)->table($p.'User')->count(),
            'fetch' => fn (int $after, int $limit, Ctx $ctx) => Profiles::page($ctx->src()->table($p.'User'), 'UserID', $after, $limit, function ($u) {
                $photo = trim((string) ($u->Photo ?? ''));
                $avatar = null;
                if (preg_match('#^(https?:)?//#i', $photo)) {
                    $avatar = ['url' => $photo];
                } elseif ($photo !== '' && ! str_starts_with($photo, '~')) {
                    $full = dirname($photo).'/p'.basename($photo);
                    $avatar = ['root' => 'uploads', 'path' => [$full, $photo], 'url' => 'uploads/'.$full];
                }
                $dob = (string) ($u->DateOfBirth ?? '');

                return ['avatar' => $avatar, 'birthday' => str_starts_with($dob, '0000') ? null : Profiles::date($dob)];
            }),
        ];
    }

    /**
     * Attachments: `Media` rows of the discussion ('d<id>') or comment
     * ('c<id>'), `Path` under uploads/. Images a Rich post shows are
     * addresses on the old board's uploads, copied where they stand.
     */
    public static function attachments(array $cfg): array
    {
        $p = self::prefix($cfg);

        return ['fetch' => function (array $ids, Ctx $ctx) use ($p) {
            $conn = $ctx->src();
            if (! $conn->getSchemaBuilder()->hasTable($p.'Media')) {
                return [];
            }
            $by = ['discussion' => [], 'comment' => []];
            foreach ($ids as $id) {
                $by[$id[0] === 'd' ? 'discussion' : 'comment'][] = (int) substr($id, 1);
            }
            $out = [];
            foreach ($conn->table($p.'Media')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('ForeignTable', 'discussion')->whereIn('ForeignID', $by['discussion'] ?: [0]))
                ->orWhere(fn ($w) => $w->where('ForeignTable', 'comment')->whereIn('ForeignID', $by['comment'] ?: [0])))
                ->orderBy('MediaID')->get() as $m) {
                $path = ltrim((string) $m->Path, '/');
                $out[($m->ForeignTable === 'discussion' ? 'd' : 'c').$m->ForeignID][] = [
                    'id' => (string) $m->MediaID, 'name' => (string) $m->Name, 'uid' => $m->InsertUserID ?? null,
                    'root' => 'uploads', 'path' => $path, 'url' => preg_match('#^https?://#i', $path) ? $path : 'uploads/'.$path,
                    'keys' => ['uploads/'.$path],
                ];
            }

            return $out;
        }, 'inline' => '#/uploads/#'];
    }

    /**
     * Of these permission categories, the ones a guest or member may view:
     * `Permission` rows for the category junction whose
     * `Vanilla.Discussions.View` is on, for the Guest and Member roles (by
     * `Role.Type` where Vanilla has it, else the stock ids 2 and 8).
     * Null where the board has no such tables: nothing is restricted then.
     *
     * @param  array<int|string>  $perms
     * @return array<int, true>|null
     */
    private static function openCategories(ConnectionInterface $conn, string $p, array $perms): ?array
    {
        $sb = $conn->getSchemaBuilder();
        if (! $sb->hasTable($p.'Permission') || ! $sb->hasColumn($p.'Category', 'PermissionCategoryID')) {
            return null;
        }
        if (! $perms) {
            return [];
        }
        $roles = $sb->hasTable($p.'Role') && $sb->hasColumn($p.'Role', 'Type')
            ? $conn->table($p.'Role')->whereIn('Type', ['guest', 'member'])->pluck('RoleID')->all() : [];
        $open = [];
        // 🚨 Selected whole and read in PHP: the builder would quote the dotted
        // column name as database.table.column.
        foreach ($conn->table($p.'Permission')->where('JunctionTable', 'Category')->whereIn('JunctionID', $perms)
            ->whereIn('RoleID', $roles ?: [2, 8])->get() as $row) {
            if ((int) (((array) $row)['Vanilla.Discussions.View'] ?? 0) === 1) {
                $open[(int) $row->JunctionID] = true;
            }
        }

        return $open;
    }

    /**
     * Conversations: `Conversation` (`Subject`, often empty), `ConversationMessage`
     * (`Body` in the row's `Format`) and `UserConversation`, a row per
     * participant: `Deleted` once they left it, `DateCleared` when they cleared
     * everything before that moment, `DateLastViewed` how far they have read.
     */
    public static function privateMessages(array $cfg): array
    {
        $p = self::prefix($cfg);
        $has = fn ($conn) => $conn->getSchemaBuilder()->hasTable($p.'ConversationMessage') && $conn->getSchemaBuilder()->hasTable($p.'UserConversation');

        return [
            'count' => function () use ($cfg, $p, $has) {
                $conn = Src::connect($cfg);

                return $has($conn) ? (int) $conn->table($p.'ConversationMessage')->count() : 0;
            },
            'fetch' => function (int $after, int $limit, Ctx $ctx) use ($p, $has) {
                $conn = $ctx->src();
                if (! $has($conn)) {
                    return ['cursor' => $after, 'rows' => 0, 'done' => true, 'messages' => []];
                }
                $rows = $conn->table($p.'ConversationMessage')->where('MessageID', '>', $after)->orderBy('MessageID')->limit($limit)->get();
                $ids = $rows->pluck('ConversationID')->unique()->values()->all();
                $subjects = $ids && $conn->getSchemaBuilder()->hasTable($p.'Conversation')
                    ? $conn->table($p.'Conversation')->whereIn('ConversationID', $ids)->pluck('Subject', 'ConversationID')->all() : [];
                $who = [];
                foreach ($ids ? $conn->table($p.'UserConversation')->whereIn('ConversationID', $ids)->get() : [] as $r) {
                    $who[$r->ConversationID][] = $r;
                }
                $out = [];
                foreach ($rows as $m) {
                    $after = (int) $m->MessageID;
                    $at = Src::ts($m->DateInserted ?? null);
                    $rs = $who[$m->ConversationID] ?? [];
                    $kept = array_filter($rs, fn ($r) => ! (int) ($r->Deleted ?? 0) && (empty($r->DateCleared) || Src::ts($r->DateCleared) < $at));
                    $out[] = [
                        'id' => (string) $m->MessageID,
                        'conv' => (string) $m->ConversationID,
                        'thread' => (string) $m->ConversationID,
                        'title' => (string) ($subjects[$m->ConversationID] ?? ''),
                        'uid' => $m->InsertUserID,
                        'people' => array_map(fn ($r) => $r->UserID, $rs),
                        'keep' => array_map(fn ($r) => $r->UserID, $kept),
                        'unread' => array_map(fn ($r) => $r->UserID, array_filter($kept, fn ($r) => empty($r->DateLastViewed) || Src::ts($r->DateLastViewed) < $at)),
                        'html' => self::body($m->Body ?? '', $m->Format ?? ''),
                        'at' => $at,
                    ];
                }

                return ['cursor' => $after, 'rows' => count($rows), 'done' => count($rows) < $limit, 'messages' => $out];
            },
        ];
    }

    /** Vanilla's HashMethod says how Password was made; the ones it can be checked against are kept. */
    private static function legacy(object $u): ?array
    {
        $hash = (string) ($u->Password ?? '');
        $method = strtolower((string) ($u->HashMethod ?? 'vanilla'));
        if ($hash === '') {
            return null;
        }

        return match ($method) {
            'vanilla', 'phpbb', 'wordpress', '' => Src::legacy($hash, str_starts_with($hash, '$2') && ! preg_match('#^\$2[aby]\$\d\d\$.{53}$#', $hash) ? 'native' : null),
            'django' => ['scheme' => 'django', 'hash' => $hash, 'salt' => null],
            'md5' => ['scheme' => 'md5', 'hash' => $hash, 'salt' => null],
            default => ['scheme' => 'unknown', 'hash' => $hash, 'salt' => null],
        };
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
                // Vanilla's Quotes plugin keeps the author in rel=: <blockquote class="Quote" rel="Kryptos">.
                return Html::textMentions($body);
            case 'bbcode':
                return Bbcode::toHtml($body);
            case 'markdown':
                // ...and in Markdown opens the quote with "> @Kryptos said:" (in the board's
                // language). Left as it is, Flarum reads @Kryptos as a mention and links it
                // to whichever member now has that username — or, for "@John Smith", to John.
                return preg_replace_callback(
                    '#(<blockquote>\s*<p>)@([^\n<]{1,100}:)(?:<br>\n?|\n|(?=</p>))#u',
                    fn ($m) => $m[1].'<strong>'.str_replace('&quot;', '', $m[2]).'</strong><br>',
                    Src::markdown($body, ['html' => true])
                ) ?? '';
            case 'rich':
            case 'rich2':
                return VanillaRich::toHtml($body) ?? self::textToHtml($body);
            default: // 'text', 'textex' and anything unknown
                return Html::textMentions(self::textToHtml($body));
        }
    }

    private static function textToHtml(string $text): string
    {
        $out = '';
        foreach (preg_split('/\n{2,}/', trim($text)) as $block) {
            $block = trim((string) $block);
            if ($block !== '') {
                $out .= '<p>'.nl2br(htmlspecialchars($block, ENT_QUOTES), false).'</p>';
            }
        }

        return $out;
    }
}
