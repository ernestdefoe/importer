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
        self::$pendingMentions = [];
        self::$parents = [];
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

    /** A post written earlier in this same batch can already be quoted by a later one. */
    public static function ref(string $kind, string $srcId, int $targetId): void
    {
        self::$refs[$kind][$srcId] = $targetId;
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
        return self::$tables['tags'] ??= self::db()->getSchemaBuilder()->hasTable('tags');
    }

    /** Cached like hasTags(): an import asks for the same few columns once per row. */
    public static function hasColumn(string $table, string $col): bool
    {
        try {
            return self::$tables[$table.'.'.$col] ??= self::db()->getSchemaBuilder()->hasColumn($table, $col);
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

    /**
     * @param  bool  $restricted  the old board let neither guests nor members
     *                            read it (a staff forum): only admins see the
     *                            tag until its permissions are set up
     */
    public static function tag(string $name, string $slug, ?string $desc, ?string $color, int $position, ?int $parentId = null, bool $restricted = false): int
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
            'is_restricted' => $restricted ? 1 : 0,
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
    /**
     * @param  string  $username  already valid for Flarum ({@see Src::username()})
     * @param  bool  $confirmed  the old board had confirmed the address
     * @param  Carbon|null  $suspendedUntil  banned there: suspended here until then
     *                                       (far in the future for a permanent ban), with flarum/suspend
     * @param  string|null  $display  the member's name as the old board showed it,
     *                                kept as their nickname where flarum/nicknames is installed
     */
    public static function user(string $username, string $email, ?string $passwordHash, Carbon $joinedAt, ?Carbon $lastSeenAt = null, ?array $legacy = null, bool $confirmed = true, ?Carbon $suspendedUntil = null, ?string $display = null): int
    {
        $db = self::db();
        $email = mb_strtolower(trim($email));
        if ($id = $db->table('users')->where('email', $email)->value('id')) {
            return (int) $id;
        }

        // Flarum usernames are unique, and compared without case — disambiguate on collision.
        $base = Str::limit($username, 30, '');
        $name = $base;
        $n = 1;
        while ($db->table('users')->whereRaw('LOWER(username) = ?', [mb_strtolower($name)])->exists()) {
            $name = Str::limit($base, 26, '').'_'.(++$n);
        }

        $row = [
            'username' => $name,
            'email' => $email,
            'is_email_confirmed' => $confirmed ? 1 : 0,
            // A legacy hash is never copied here: Flarum would compare the
            // typed password with it directly, so an md5 could sign in alone.
            'password' => Src::password($legacy ? null : $passwordHash),
            'joined_at' => $joinedAt,
            'last_seen_at' => $lastSeenAt,
            // Everything imported is old news: without this every member opened
            // the new forum to thousands of unread discussions.
            'marked_all_as_read_at' => Carbon::now(),
        ];
        if ($suspendedUntil && $suspendedUntil->isFuture() && self::hasColumn('users', 'suspended_until')) {
            $row['suspended_until'] = $suspendedUntil;
        }
        $display = trim((string) $display);
        if ($display !== '' && $display !== $name && self::hasColumn('users', 'nickname')) {
            $row['nickname'] = Str::limit($display, 255, '');
        }
        $id = (int) $db->table('users')->insertGetId($row);
        if ($legacy && ($legacy['hash'] ?? '') !== '' && ($legacy['scheme'] ?? 'unknown') !== 'unknown' && (self::$tables['importer_passwords'] ??= $db->getSchemaBuilder()->hasTable('importer_passwords'))) {
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
        /*
         * 🚨 Never to an account whose address nobody has confirmed. An import
         * reuses whatever account already holds a member's email, and anyone
         * could have registered the old admin's address here before the
         * import — this made them an administrator.
         */
        if (! $db->table('users')->where('id', $userId)->where('is_email_confirmed', true)->exists()) {
            return;
        }
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
     * Rehost a post attachment the old board kept onto the public
     * `flarum-assets` disk and return its URL.
     *
     * 🚨 That disk is served straight from the web root, so the extension is
     * never taken on trust from the old board: an image keeps the extension
     * its content proves, a known document type keeps its own, and anything
     * else (.php, .html, .svg, …) is stored as .bin so it can only ever be
     * downloaded, never executed or rendered on the forum's origin.
     *
     * With FoF Upload enabled the file goes where its local adapter keeps
     * files (assets/files/<date>/…), and `upload` is its `fof_upload_files`
     * row, owned by $actorId, for the caller to write with the rest of its
     * batch ({@see Attachments::done()}).
     *
     * @return array{url:string,isImage:bool,upload:?array}|null
     */
    public static function storeAsset(string $bytes, string $filename, ?int $actorId = null): ?array
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
        $fof = self::fofUpload();
        $file = ($fof ? date('Y-m-d').'/' : '').Str::random(16).'-'.$safeName.'.'.$ext;
        $path = ($fof ? 'files/' : 'attachments/').$file;

        try {
            $disk = resolve(\Illuminate\Contracts\Filesystem\Factory::class)->disk('flarum-assets');
            $disk->put($path, $bytes);
            $url = $disk->url($path);
            $upload = null;
            if ($fof) {
                $mime = $isImage ? (string) getimagesizefromstring($bytes)['mime'] : ($ext === 'bin' ? 'application/octet-stream'
                    : ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'));
                $upload = [
                    'actor_id' => $actorId,
                    'base_name' => Str::limit(pathinfo($filename, PATHINFO_FILENAME).'.'.$ext, 255, ''),
                    'path' => $file,
                    'url' => $url,
                    'type' => $mime,
                    'size' => strlen($bytes),
                    'upload_method' => 'local',
                    'created_at' => Carbon::now(),
                    'uuid' => (string) Str::uuid(),
                    'tag' => $isImage ? 'image-preview' : 'file',
                ];
            }

            return ['url' => $url, 'isImage' => $isImage, 'upload' => $upload];
        } catch (\Throwable) {
            return null;
        }
    }

    /** FoF Upload is enabled, so an attachment becomes one of its files. */
    private static function fofUpload(): bool
    {
        return self::$tables['@fof-upload'] ??= (function () {
            try {
                return resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled('fof-upload') && self::hasTable('fof_upload_files');
            } catch (\Throwable) {
                return false;
            }
        })();
    }

    public static function hasTable(string $table): bool
    {
        return self::$tables[$table] ??= self::db()->getSchemaBuilder()->hasTable($table);
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

    /** @param  bool  $hidden  hidden there (unlisted by staff): only moderators see it here */
    public static function discussion(string $title, ?int $userId, Carbon $createdAt, bool $sticky = false, bool $locked = false, bool $hidden = false): int
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
            'hidden_at' => $hidden ? $createdAt : null,
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

    /** @var array<int,?int> tag id → its parent, for the step */
    private static array $parents = [];

    /**
     * A discussion in a child tag is in its parent as well, as flarum/tags
     * files one (its tag pages and counts read the parent's own rows).
     */
    public static function attachTag(int $discussionId, int $tagId): void
    {
        $db = self::db();
        $parent = array_key_exists($tagId, self::$parents) ? self::$parents[$tagId]
            : (self::$parents[$tagId] = ($p = $db->table('tags')->where('id', $tagId)->value('parent_id')) ? (int) $p : null);
        $tags = array_filter([$tagId, $parent]);
        $have = $db->table('discussion_tag')->where('discussion_id', $discussionId)->whereIn('tag_id', $tags)->pluck('tag_id')->map(fn ($t) => (int) $t)->all();
        $rows = [];
        foreach (array_diff($tags, $have) as $t) {
            $rows[] = ['discussion_id' => $discussionId, 'tag_id' => $t, 'created_at' => Carbon::now()];
        }
        if ($rows) {
            $db->table('discussion_tag')->insert($rows);
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
        $id = (int) self::db()->table('posts')->insertGetId([
            'discussion_id' => $discussionId,
            'number' => $number,
            'created_at' => $createdAt,
            'user_id' => $userId,
            'type' => 'comment',
            'content' => $content,
            'is_private' => 0,
        ]);
        if (str_contains($content, 'MENTION ')) {
            self::$pendingMentions[$id] = $content; // written in one go per step, see flushMentions()
        }

        return $id;
    }

    /** Where flarum/mentions (and flarum/messages) record who and what a post mentions. */
    private const MENTIONS = [
        'USERMENTION' => ['user', 'mentions_user_id'],
        'POSTMENTION' => ['post', 'mentions_post_id'],
        'GROUPMENTION' => ['group', 'mentions_group_id'],
        'TAGMENTION' => ['tag', 'mentions_tag_id'],
    ];

    /** @var array<int,string> posts written this step whose mentions are not recorded yet */
    private static array $pendingMentions = [];

    /** Record the mentions of the posts written since the last call: a few inserts per batch, not per post. */
    public static function flushMentions(): void
    {
        if (self::$pendingMentions) {
            $pending = self::$pendingMentions;
            self::$pendingMentions = [];
            self::mentions('posts', $pending);
        }
    }

    /** @var array<string,bool> which tables exist; kept across steps, as a schema does not change mid-request */
    private static array $tables = [];

    /**
     * Record the mentions in stored posts or messages, as flarum/mentions does
     * when a post is written on the forum.
     *
     * 🚨 The mention renders from these rows, not from the post: without them
     * every imported mention shows as "@[deleted]" and the quoted post's link
     * goes nowhere. Flarum fills them from an event an import never fires.
     *
     * @param  'posts'|'dialog_messages'  $table
     * @param  array<int,string>  $contents  id => stored XML
     * @param  bool  $replace  the posts already had rows (an edit, a reformat)
     */
    public static function mentions(string $table, array $contents, bool $replace = false): void
    {
        [$prefix, $key] = $table === 'posts' ? ['post_mentions_', 'post_id'] : ['dialog_message_mentions_', 'dialog_message_id'];
        $db = self::db();
        $now = Carbon::now();
        foreach (self::MENTIONS as $tag => [$kind, $col]) {
            $pivot = $prefix.$kind;
            $rows = [];
            foreach ($contents as $id => $xml) {
                if (! str_contains($xml, '<'.$tag.' ')) {
                    continue;
                }
                foreach (array_unique(\s9e\TextFormatter\Utils::getAttributeValues($xml, $tag, 'id')) as $v) {
                    if ((int) $v > 0) {
                        $rows[] = [$key => (int) $id, $col => (int) $v, 'created_at' => $now];
                    }
                }
            }
            if ((! $rows && ! $replace) || ! (self::$tables[$pivot] ??= $db->getSchemaBuilder()->hasTable($pivot))) {
                continue;
            }
            if ($replace) {
                $db->table($pivot)->whereIn($key, array_keys($contents))->delete();
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table($pivot)->insertOrIgnore($chunk);
            }
        }
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
