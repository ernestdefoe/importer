<?php

namespace ErnestDefoe\Importer\Importers;

use Flarum\Formatter\Formatter;
use Flarum\User\AvatarUploader;
use Flarum\User\User as FlarumUser;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Destination writer — turns the platform-agnostic rows the importers produce
 * into Flarum records (tags, users, discussions, posts). This is the part that
 * differs from Convoro: everything writes into Flarum's schema, and post bodies
 * are HTML → Markdown → run through Flarum's formatter so they store in the
 * parsed `content` form Flarum expects (`<t>…</t>` / `<r>…</r>`).
 */
class Dst
{
    private static ?ConnectionInterface $db = null;
    private static ?Formatter $formatter = null;
    private static ?HtmlConverter $html = null;

    /** A dry run (importer:run --sync --dry-run) rolls the database back; files it would write are skipped. */
    public static bool $dryRun = false;

    public static function reset(): void
    {
        self::$db = null;
        self::$formatter = null;
        self::$html = null;
    }

    public static function db(): ConnectionInterface
    {
        return self::$db ??= resolve(ConnectionInterface::class);
    }

    private static function formatter(): Formatter
    {
        return self::$formatter ??= resolve(Formatter::class);
    }

    private static function html(): HtmlConverter
    {
        return self::$html ??= new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => true,
            'use_autolinks' => false,
            'header_style' => 'atx',
        ]);
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
        $md = trim(PostText::fixEntities(self::html()->convert($html !== '' ? $html : ' ')));
        if ($md === '') {
            $md = '​'; // zero-width space so the post isn't empty
        }

        try {
            return self::formatter()->parse($md, null);
        } catch (\Throwable) {
            // Never let one weird post kill the run — fall back to plain text.
            return self::formatter()->parse(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: '​', null);
        }
    }

    /* ── Tags (categories) ──────────────────────────────────────────────── */

    public static function tag(string $name, string $slug, ?string $desc, ?string $color, int $position, bool $isPrimary = true, ?int $parentId = null): int
    {
        $db = self::db();
        if ($id = $db->table('tags')->where('slug', $slug)->value('id')) {
            return (int) $id;
        }

        $row = [
            'name' => Str::limit($name, 100, ''),
            'slug' => $slug,
            'description' => $desc !== null ? Str::limit(strip_tags($desc), 700, '') : null,
            'color' => Src::color($color),
            'position' => $position,
            'is_restricted' => 0,
            'is_hidden' => 0,
            'discussion_count' => 0,
        ];
        if (self::hasColumn('tags', 'is_primary')) {
            $row['is_primary'] = $isPrimary;
        }
        if ($parentId !== null && self::hasColumn('tags', 'parent_id')) {
            $row['parent_id'] = $parentId;
        }

        return (int) $db->table('tags')->insertGetId($row);
    }

    /* ── Users ──────────────────────────────────────────────────────────── */

    public static function user(string $username, string $email, ?string $passwordHash, Carbon $joinedAt, ?Carbon $lastSeenAt = null): int
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

        return (int) $db->table('users')->insertGetId([
            'username' => $name,
            'email' => $email,
            'is_email_confirmed' => 1,
            'password' => Src::password($passwordHash),
            'joined_at' => $joinedAt,
            'last_seen_at' => $lastSeenAt ?? $joinedAt,
        ]);
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

    /**
     * Store raw avatar image bytes for an already-imported user, going through
     * Flarum's own AvatarUploader so it gets resized/re-encoded and its @2x/@3x
     * variants generated exactly like a normal upload would.
     *
     * Best-effort: skips silently (returns false) on empty/undecodable/corrupt
     * image data — one bad avatar must never abort an import run. A user who
     * already has an avatar (e.g. re-running an import) is left untouched.
     */
    public static function avatarBytes(int $userId, string $binaryData): bool
    {
        if ($binaryData === '') {
            return false;
        }
        /** @var FlarumUser|null $user */
        $user = FlarumUser::find($userId);
        if (! $user || $user->getRawOriginal('avatar_url')) {
            return false;
        }
        try {
            $image = resolve(ImageManager::class)->read($binaryData);
            resolve(AvatarUploader::class)->upload($user, $image);
            $user->save();

            return true;
        } catch (\Throwable) {
            // Corrupt/unsupported source image (bad GIF, truncated JPEG, …) —
            // skip this one avatar rather than failing the whole batch.
            return false;
        }
    }

    /**
     * Store arbitrary file bytes (an old forum post's attachment, not an
     * avatar) onto Flarum's own generic public asset disk (`flarum-assets`,
     * served from `assets/`) and return its public URL. Used to rehost
     * embedded images/files a source forum's posts reference, since
     * Flarum's post content just needs an `<img src>`/link — no upload
     * extension is required to *display* something already on disk.
     *
     * A random filename prefix avoids collisions between posts and hides
     * the old forum's internal numbering; the original filename is kept as
     * a suffix (sanitised) purely so a downloaded file looks reasonable.
     * Best-effort: returns null on empty input or a write failure — one
     * broken attachment must never abort the import run.
     */
    public static function storeAsset(string $binaryData, string $filename): ?string
    {
        if ($binaryData === '') {
            return null;
        }
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $ext = preg_match('/^[a-zA-Z0-9]{1,10}$/', $ext) ? '.' . strtolower($ext) : '';
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($filename, PATHINFO_FILENAME));
        $safeName = trim(Str::limit($safeName ?: 'file', 60, ''), '-');
        $path = 'attachments/' . Str::random(16) . '-' . $safeName . $ext;

        try {
            $disk = resolve(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-assets');
            $disk->put($path, $binaryData);

            return $disk->url($path);
        } catch (\Throwable) {
            return null;
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

        try {
            $bytes = SafeFetch::get($source, 3_000_000, 15, (string) parse_url($assetsBase, PHP_URL_HOST));

            // 3MB ceiling: an avatar is rendered at 96px and a board being
            // migrated is not the place to discover somebody uploaded a
            // twenty-megabyte PNG.
            if ($bytes === null || strlen($bytes) < 64) {
                return false;
            }

            $info = @getimagesizefromstring($bytes);

            if ($info === false) {
                return false;
            }

            $ext = match ($info['mime']) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => null,
            };

            if ($ext === null) {
                return false;
            }

            $name = Str::random(20).'.'.$ext;

            /*
             * The `flarum-avatars` disk, the same one core writes to, so the
             * files land where core expects and are served by the same URL
             * generator rather than a path guessed here.
             */
            $disk = resolve(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-avatars');
            $disk->put($name, $bytes);

            self::db()->table('users')->where('id', $userId)->update(['avatar_url' => $name]);

            return true;
        } catch (\Throwable) {
            return false;
        }
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
