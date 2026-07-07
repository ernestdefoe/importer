<?php

namespace ErnestDefoe\Importer\Importers;

use Flarum\Formatter\Formatter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        $md = trim(self::html()->convert($html !== '' ? $html : ' '));
        if ($md === '') {
            $md = '​'; // zero-width space so the post isn't empty
        }
        try {
            return self::formatter()->parse($md, null);
        } catch (\Throwable) {
            // Never let one weird post kill the run — fall back to plain text.
            return self::formatter()->parse(strip_tags($html) ?: '​', null);
        }
    }

    /* ── Tags (categories) ──────────────────────────────────────────────── */

    public static function tag(string $name, string $slug, ?string $desc, ?string $color, int $position): int
    {
        $db = self::db();
        if ($id = $db->table('tags')->where('slug', $slug)->value('id')) {
            return (int) $id;
        }

        return (int) $db->table('tags')->insertGetId([
            'name' => Str::limit($name, 100, ''),
            'slug' => $slug,
            'description' => $desc !== null ? Str::limit(strip_tags($desc), 700, '') : null,
            'color' => Src::color($color),
            'position' => $position,
            'is_restricted' => 0,
            'is_hidden' => 0,
            'discussion_count' => 0,
        ]);
    }

    /* ── Users ──────────────────────────────────────────────────────────── */

    public static function user(string $username, string $email, ?string $passwordHash, Carbon $joinedAt): int
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
            $name = Str::limit($base, 26, '') . '_' . (++$n);
        }

        return (int) $db->table('users')->insertGetId([
            'username' => $name,
            'email' => $email,
            'is_email_confirmed' => 1,
            'password' => Src::password($passwordHash),
            'joined_at' => $joinedAt,
        ]);
    }

    /* ── Discussions (topics) ───────────────────────────────────────────── */

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
        return (int) self::db()->table('posts')->insertGetId([
            'discussion_id' => $discussionId,
            'number' => $number,
            'created_at' => $createdAt,
            'user_id' => $userId,
            'type' => 'comment',
            'content' => self::content($html),
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
        $agg = $db->table('posts')->where('discussion_id', $did)->where('type', 'comment')
            ->selectRaw('COUNT(*) c, MIN(id) first_id, COUNT(DISTINCT user_id) parts')->first();
        if (! $agg || ! $agg->c) {
            return;
        }
        $last = $db->table('posts')->where('discussion_id', $did)->orderByDesc('number')->orderByDesc('id')
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
