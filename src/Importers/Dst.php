<?php

namespace ErnestDefoe\Importer\Importers;

use ErnestDefoe\Importer\Markup\Html;
use ErnestDefoe\Importer\Markup\Target;
use ErnestDefoe\Importer\Markup\Writer;
use Flarum\Formatter\Formatter;
use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Destination writer — turns the platform-agnostic rows the importers produce
 * into Flarum records (tags, users, discussions, posts). This is the part that
 * differs from Convoro: everything writes into Flarum's schema, and post bodies
 * are canonical HTML ({@see Html}) written in whatever syntax this forum's
 * editor uses ({@see Writer}), then run through Flarum's formatter so they
 * store in the parsed `content` form Flarum expects (`<t>…</t>` / `<r>…</r>`).
 */
class Dst
{
    private static ?ConnectionInterface $db = null;
    private static ?Formatter $formatter = null;

    /** A dry run (importer:run --sync --dry-run) rolls the database back; files it would write are skipped. */
    public static bool $dryRun = false;

    /** The old board's address (the run's assets_base), for its relative links and images. */
    public static string $base = '';

    /** Source ids in the batch being written → Flarum ids, for mentions, quoted posts and topic links. */
    private static array $refs = [];

    public static function reset(): void
    {
        self::$db = null;
        self::$formatter = null;
        self::$refs = [];
        self::$base = '';
        Target::set(null);
    }

    public static function db(): ConnectionInterface
    {
        return self::$db ??= resolve(ConnectionInterface::class);
    }

    private static function formatter(): Formatter
    {
        return self::$formatter ??= resolve(Formatter::class);
    }

    /**
     * Look up, in one go for a whole batch, everything its posts point at:
     * mentioned members (by source id or by name), quoted posts and linked
     * topics. {@see content()} then writes `@"Ada"#12` and `@"Ada"#p45`
     * without a query per post.
     *
     * @param  string[]  $htmls  the batch's canonical HTML
     * @param  bool  $posts  false for private messages: a quoted message is not a post
     */
    public static function prepare(Ctx $ctx, array $htmls, bool $posts = true): void
    {
        $want = ['user' => [], 'post' => [], 'topic' => []];
        $names = [];
        foreach ($htmls as $h) {
            if (! str_contains((string) $h, 'data-')) {
                continue;
            }
            preg_match_all('/data-(user|post|topic)="([^"]+)"/', (string) $h, $m, PREG_SET_ORDER);
            foreach ($m as [, $kind, $id]) {
                if ($kind !== 'post' || $posts) {
                    $want[$kind][] = $id;
                }
            }
            preg_match_all('/data-user-name="([^"]+)"/', (string) $h, $m);
            foreach ($m[1] as $n) {
                $names[] = html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $refs = array_filter($want) ? $ctx->mapMany(array_filter($want)) : [];
        if ($names) {
            $byName = [];
            foreach (array_unique($names) as $n) {
                $byName[Src::username($n, 0)] = mb_strtolower($n);
            }
            foreach (array_chunk(array_keys($byName), 500) as $chunk) {
                foreach (self::db()->table('users')->whereIn('username', $chunk)->get(['id', 'username']) as $u) {
                    $refs['name'][$byName[$u->username] ?? mb_strtolower($u->username)] = (int) $u->id;
                }
            }
        }
        self::$refs = $refs;
    }

    /** The text the forum's formatter is given for a body: written for whichever editor the forum uses. */
    public static function text(string $html): string
    {
        $root = Html::cleanRoot($html, ['base' => self::$base]);
        $text = $root ? Writer::write($root, Target::current(), self::$refs, self::topicUrl(...)) : '';

        return $text !== '' ? $text : "\u{200B}"; // zero-width space so the post isn't empty
    }

    private static function topicUrl(int $did): string
    {
        try {
            return resolve(UrlGenerator::class)->to('forum')->route('discussion', ['id' => $did]);
        } catch (\Throwable) {
            return '/d/'.$did;
        }
    }

    public static function hasTags(): bool
    {
        return self::db()->getSchemaBuilder()->hasTable('tags');
    }

    public static function hasColumn(string $table, string $col): bool
    {
        try {
            return self::db()->getSchemaBuilder()->hasColumn($table, $col);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Convert an HTML body into Flarum's stored (parsed) content form. */
    public static function content(string $html): string
    {
        try {
            return self::formatter()->parse(self::text($html), null);
        } catch (\Throwable) {
            // Never let one weird post kill the run — fall back to plain text.
            return self::formatter()->parse(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: "\u{200B}", null);
        }
    }

    /* ── Tags (categories) ──────────────────────────────────────────────── */

    public static function tag(string $name, string $slug, ?string $desc, ?string $color, int $position, ?int $parentId = null): int
    {
        $db = self::db();
        if ($id = $db->table('tags')->where('slug', $slug)->value('id')) {
            return (int) $id;
        }

        // A positioned tag is in the primary tree — a child of a primary tag
        // too, exactly as flarum/tags' own OrderTagsController stores it.
        return (int) $db->table('tags')->insertGetId([
            'name' => Str::limit($name, 100, ''),
            'slug' => $slug,
            'description' => $desc !== null ? Str::limit(strip_tags($desc), 700, '') : null,
            'color' => Src::color($color),
            'position' => $position,
            'parent_id' => $parentId,
            'is_primary' => true,
            'is_restricted' => 0,
            'is_hidden' => 0,
            'discussion_count' => 0,
        ]);
    }

    /* ── Users ──────────────────────────────────────────────────────────── */

    /**
     * @param  array{scheme:string,hash:string,salt?:?string}|null  $legacy
     *         the old board's hash when Flarum cannot check it itself; kept in
     *         importer_passwords for the member's first sign-in ({@see \ErnestDefoe\Importer\Auth\LegacyPasswordChecker})
     */
    public static function user(string $username, string $email, ?string $passwordHash, Carbon $joinedAt, ?Carbon $lastSeenAt = null, ?array $legacy = null): int
    {
        $db = self::db();
        $email = mb_strtolower(trim($email));
        if ($id = $db->table('users')->where('email', $email)->value('id')) {
            return (int) $id;
        }

        // Flarum usernames are unique — disambiguate on collision.
        $base = Str::limit($username, 30, '');
        $name = $base;
        $n = 1;
        while ($db->table('users')->where('username', $name)->exists()) {
            $name = Str::limit($base, 26, '').'_'.(++$n);
        }

        $id = (int) $db->table('users')->insertGetId([
            'username' => $name,
            'email' => $email,
            'is_email_confirmed' => 1,
            // A legacy hash is never copied here: Flarum would compare the
            // typed password with it directly, so an md5 could sign in alone.
            'password' => Src::password($legacy ? null : $passwordHash),
            'joined_at' => $joinedAt,
            'last_seen_at' => $lastSeenAt,
        ]);
        if ($legacy && ($legacy['hash'] ?? '') !== '' && ($legacy['scheme'] ?? 'unknown') !== 'unknown' && $db->getSchemaBuilder()->hasTable('importer_passwords')) {
            $db->table('importer_passwords')->insert([
                'user_id' => $id,
                'scheme' => $legacy['scheme'],
                'hash' => Str::limit((string) $legacy['hash'], 255, ''),
                'salt' => isset($legacy['salt']) ? Str::limit((string) $legacy['salt'], 255, '') : null,
                'created_at' => Carbon::now(),
            ]);
        }

        return $id;
    }

    /**
     * Assign an imported user to Flarum's built-in Admin (1) or Mod (4)
     * group. Best-effort and idempotent — safe to call even if the group
     * assignment already exists (e.g. a user matched by email to an
     * already-imported account). Never assigns Guest (2) or Member (3);
     * those are Flarum's implicit defaults and don't need a row.
     */
    public static function assignCoreGroup(int $userId, int $groupId): void
    {
        if (! in_array($groupId, [1, 4], true)) {
            return;
        }
        $db = self::db();
        $exists = $db->table('group_user')->where('user_id', $userId)->where('group_id', $groupId)->exists();
        if (! $exists) {
            $db->table('group_user')->insert(['user_id' => $userId, 'group_id' => $groupId]);
        }
    }

    /* ── Discussions (topics) ───────────────────────────────────────────── */

    /**
     * Fetch a member's avatar from the old board and store it on this one.
     *
     * 🚨 Copied, never linked. Pointing at the old site's URL leaves every
     * avatar on the board dependent on a host that is about to be switched
     * off — which is precisely the migration this runs during. The image is
     * fetched once and lives here afterwards.
     *
     * Best effort by design: an avatar that 404s is a member with no picture,
     * not a failed import. Nothing here may throw.
     */
    public static function avatar(int $userId, ?string $source, string $assetsBase = ''): bool
    {
        $source = trim((string) $source);

        if ($source === '' || $userId <= 0 || self::$dryRun) {
            return false;
        }

        // Relative paths need the old board's origin; absolute ones carry it.
        if (! preg_match('#^https?://#i', $source)) {
            if ($assetsBase === '') {
                return false;
            }

            $source = rtrim($assetsBase, '/').'/'.ltrim($source, '/');
        }

        // 3MB ceiling: an avatar is rendered at 96px and a board being
        // migrated is not the place to discover somebody uploaded a
        // twenty-megabyte PNG.
        $bytes = SafeFetch::get($source, 3_000_000, 15, (string) parse_url($assetsBase, PHP_URL_HOST));

        return $bytes !== null && self::avatarBytes($userId, $bytes);
    }

    /**
     * Store an avatar the old board kept itself (a database blob, or a file
     * on disk that importer:run --avatar-path points at).
     *
     * Only a real PNG/JPEG/GIF/WebP under 3MB is kept, stored under a random
     * name with the extension its CONTENT says, on the `flarum-avatars` disk
     * core writes to — so the file lands where core expects and is served by
     * the same URL generator rather than a path guessed here.
     */
    public static function avatarBytes(int $userId, string $bytes): bool
    {
        if ($userId <= 0 || self::$dryRun || strlen($bytes) < 64 || strlen($bytes) > 3_000_000) {
            return false;
        }

        $ext = self::imageExt($bytes);

        if ($ext === null) {
            return false;
        }

        try {
            $name = Str::random(20).'.'.$ext;
            resolve(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-avatars')->put($name, $bytes);
            self::db()->table('users')->where('id', $userId)->update(['avatar_url' => $name]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Extensions an attachment that is not an image may keep; anything else is stored as .bin. */
    private const SAFE_FILE_EXTS = ['pdf', 'txt', 'csv', 'zip', 'gz', 'tar', '7z', 'rar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'mp3', 'ogg', 'wav', 'mp4', 'webm', 'mov'];

    /**
     * Rehost a post attachment the old board kept in its database onto the
     * public `flarum-assets` disk and return its URL.
     *
     * 🚨 That disk is served straight from the web root, so the extension is
     * never taken on trust from the old board: an image keeps the extension
     * its content proves, a known document type keeps its own, and anything
     * else (.php, .html, .svg, …) is stored as .bin so it can only ever be
     * downloaded, never executed or rendered on the forum's origin.
     *
     * @return array{url:string,isImage:bool}|null
     */
    public static function storeAsset(string $bytes, string $filename): ?array
    {
        if ($bytes === '' || self::$dryRun) {
            return null;
        }

        $ext = self::imageExt($bytes);
        $isImage = $ext !== null;
        if (! $isImage) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $ext = in_array($ext, self::SAFE_FILE_EXTS, true) ? $ext : 'bin';
        }
        $safeName = trim(Str::limit((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($filename, PATHINFO_FILENAME)), 60, ''), '-') ?: 'file';
        $path = 'attachments/'.Str::random(16).'-'.$safeName.'.'.$ext;

        try {
            $disk = resolve(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-assets');
            $disk->put($path, $bytes);

            return ['url' => $disk->url($path), 'isImage' => $isImage];
        } catch (\Throwable) {
            return null;
        }
    }

    /** png / jpg / gif / webp when the bytes really are that image, else null. */
    private static function imageExt(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        return match ($info['mime'] ?? null) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * Give a tag the cover image its forum had, when tag-covers is installed.
     *
     * 🚨 Silently does nothing without that extension, rather than failing.
     * A cover is decoration; an import that aborts because the board has no
     * way to store one has its priorities backwards.
     */
    public static function tagCover(int $tagId, ?string $source, string $assetsBase = ''): bool
    {
        $source = trim((string) $source);

        if ($source === '' || $tagId <= 0 || self::$dryRun) {
            return false;
        }

        if (! class_exists('\\Ernestdefoe\\TagCovers\\CoverStore')) {
            return false;
        }

        if (! preg_match('#^https?://#i', $source)) {
            if ($assetsBase === '') {
                return false;
            }

            $source = rtrim($assetsBase, '/').'/'.ltrim($source, '/');
        }

        try {
            $bytes = SafeFetch::get($source, 12_000_000, 20, (string) parse_url($assetsBase, PHP_URL_HOST));

            if ($bytes === null || strlen($bytes) < 64) {
                return false;
            }

            $store = resolve('\\Ernestdefoe\\TagCovers\\CoverStore');
            $path = $store->putContents($tagId, $bytes, 'cover');

            self::db()->table('tag_covers')->updateOrInsert(
                ['tag_id' => $tagId],
                ['path' => $path, 'updated_at' => date('Y-m-d H:i:s')]
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function discussion(string $title, ?int $userId, Carbon $createdAt, bool $sticky = false, bool $locked = false): int
    {
        $db = self::db();
        $title = Str::limit(trim($title) ?: 'Untitled', 200, '');
        $row = [
            'title' => $title,
            'slug' => Str::slug($title) ?: 'discussion',
            'comment_count' => 0,
            'participant_count' => 0,
            'created_at' => $createdAt,
            'user_id' => $userId,
            'last_posted_at' => $createdAt,
            'last_posted_user_id' => $userId,
            'is_private' => 0,
        ];
        // Optional columns from flarum/sticky + flarum/lock — set only if present.
        if ($sticky && self::hasColumn('discussions', 'is_sticky')) {
            $row['is_sticky'] = 1;
        }
        if ($locked && self::hasColumn('discussions', 'is_locked')) {
            $row['is_locked'] = 1;
        }

        return (int) $db->table('discussions')->insertGetId($row);
    }

    public static function attachTag(int $discussionId, int $tagId): void
    {
        $db = self::db();
        $exists = $db->table('discussion_tag')->where('discussion_id', $discussionId)->where('tag_id', $tagId)->exists();
        if (! $exists) {
            $db->table('discussion_tag')->insert(['discussion_id' => $discussionId, 'tag_id' => $tagId, 'created_at' => Carbon::now()]);
        }
    }

    /* ── Posts ──────────────────────────────────────────────────────────── */

    public static function post(int $discussionId, int $number, ?int $userId, string $html, Carbon $createdAt): int
    {
        return self::postContent($discussionId, $number, $userId, self::content($html), $createdAt);
    }

    /** Insert a post whose body is already in Flarum's stored form (see {@see content()}). */
    public static function postContent(int $discussionId, int $number, ?int $userId, string $content, Carbon $createdAt): int
    {
        return (int) self::db()->table('posts')->insertGetId([
            'discussion_id' => $discussionId,
            'number' => $number,
            'created_at' => $createdAt,
            'user_id' => $userId,
            'type' => 'comment',
            'content' => $content,
            'is_private' => 0,
        ]);
    }

    /**
     * Fill in a discussion's denormalised first/last-post + count columns from
     * its posts. Computed by aggregate query so it's correct even when the
     * discussion's posts were imported across many separate batches.
     */
    public static function finalizeDiscussion(int $did): void
    {
        $db = self::db();
        // Visible comments only, as core counts them (Discussion::comments()) —
        // a discussion a sync adds to may hold hidden replies and event posts
        // written on this forum.
        $comments = fn () => $db->table('posts')->where('discussion_id', $did)->where('type', 'comment')
            ->whereNull('hidden_at')->where('is_private', false);
        $agg = $comments()->selectRaw('COUNT(*) c, MIN(id) first_id, COUNT(DISTINCT user_id) parts')->first();
        if (! $agg || ! $agg->c) {
            return;
        }
        $last = $comments()->orderByDesc('number')->orderByDesc('id')
            ->first(['id', 'number', 'user_id', 'created_at']);
        $db->table('discussions')->where('id', $did)->update([
            'first_post_id' => $agg->first_id,
            'last_post_id' => $last->id ?? $agg->first_id,
            'last_post_number' => $last->number ?? $agg->c,
            'last_posted_at' => $last->created_at ?? null,
            'last_posted_user_id' => $last->user_id ?? null,
            'comment_count' => max(1, (int) $agg->c),
            'participant_count' => max(1, (int) $agg->parts),
        ]);
    }
}
