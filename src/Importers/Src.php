<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared helpers for the source-DB importers (connection, colour, dates,
 * passwords, slugs). Ported from the Convoro importer suite; the source-reading
 * side is platform-agnostic, so this is reused verbatim.
 */
class Src
{
    public const CONN = 'importer_src';

    /**
     * Build a runtime read connection to the source database (MySQL, PostgreSQL
     * for Discourse, or SQLite for fixtures). Registers a named connection on
     * Flarum's DatabaseManager via the config repository (Flarum's `config()`
     * helper is read-only, so we set it on the repository directly).
     */
    public static function connect(array $cfg)
    {
        $driver = $cfg['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            $conf = [
                'driver' => 'sqlite',
                'database' => $cfg['database'] ?? '',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ];
        } elseif ($driver === 'sqlsrv') {
            // Microsoft SQL Server — the backing store for ASP forums such as
            // Web Wiz. pdo_sqlsrv is absent on most shared LAMP hosting, so
            // say so plainly rather than letting PDO throw "could not find
            // driver": the fix is a different import route, not a config typo.
            if (! in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
                throw new \RuntimeException(
                    'This server cannot connect to SQL Server directly: the PHP extension pdo_sqlsrv is not installed. '
                    . 'Export the source database to a dump or CSV and use the file-upload import instead.'
                );
            }

            $conf = [
                'driver' => 'sqlsrv',
                'host' => $cfg['host'] ?: '127.0.0.1',
                'port' => (int) ($cfg['port'] ?: 1433),
                'database' => $cfg['database'] ?? '',
                'username' => $cfg['username'] ?? '',
                'password' => $cfg['password'] ?? '',
                'charset' => 'utf8',
                'prefix' => '',
                // Older SQL Server instances commonly present a self-signed
                // cert; without this the driver refuses the handshake.
                'trust_server_certificate' => true,
                'options' => [\PDO::ATTR_TIMEOUT => 8],
            ];
        } else {
            $driver = $driver === 'pgsql' ? 'pgsql' : 'mysql';
            $conf = [
                'driver' => $driver,
                'host' => $cfg['host'] ?: '127.0.0.1',
                'port' => (int) ($cfg['port'] ?: ($driver === 'pgsql' ? 5432 : 3306)),
                'database' => $cfg['database'] ?? '',
                'username' => $cfg['username'] ?? '',
                'password' => $cfg['password'] ?? '',
                'prefix' => '',
                'options' => [\PDO::ATTR_TIMEOUT => 8],
            ];
            if ($driver === 'pgsql') {
                $conf['charset'] = 'utf8';
                $conf['search_path'] = 'public';
                $conf['sslmode'] = 'prefer';
            } else {
                $conf['charset'] = 'utf8mb4';
                $conf['collation'] = 'utf8mb4_unicode_ci';
            }
        }

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = resolve('config');
        $config->set('database.connections.' . self::CONN, $conf);

        /** @var \Illuminate\Database\DatabaseManager $db */
        $db = resolve('db');
        $db->purge(self::CONN);

        return $db->connection(self::CONN);
    }

    public static function color(?string $c): string
    {
        $c = trim((string) $c);

        return preg_match('/^#?[0-9a-fA-F]{6}$/', $c) ? (str_starts_with($c, '#') ? $c : '#' . $c) : '#5b5bd6';
    }

    /**
     * Copy hashes PHP's own password_verify() already understands natively —
     * bcrypt ($2a/$2b/$2y, what Flarum itself uses) and argon2id/argon2i
     * (vBulletin 5's newer default scheme) — straight across so the
     * imported account's EXISTING password keeps working with zero
     * conversion. Any other/unrecognised format (vBulletin's legacy
     * salted-MD5 scheme, empty, null, …) gets a random bcrypt hash instead:
     * that account's old password can no longer match anything, so the
     * user must use "forgot password" — there is no way to convert a
     * legacy hash without knowing the original plaintext.
     */
    public static function password(?string $hash): string
    {
        $hash = (string) $hash;

        return preg_match('#^\$(2[aby]|argon2i(d)?)\$#', $hash) ? $hash : password_hash(Str::random(24), PASSWORD_BCRYPT);
    }

    /** Unix timestamp or datetime string → Carbon. */
    public static function ts($v): Carbon
    {
        if ($v === null || $v === '') {
            return Carbon::now();
        }
        if (is_numeric($v)) {
            return Carbon::createFromTimestamp((int) $v);
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    /** Sanitise already-HTML content (e.g. Discourse `cooked`, XenForo html). */
    public static function sanitizeHtml(?string $html): string
    {
        $html = (string) $html;
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<(iframe|object|embed|style|link|meta)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(iframe|object|embed|style|link|meta)\b[^>]*/?>#is', '', $html) ?? $html;
        $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html) ?? $html;
        $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*"\s*javascript:[^"]*"#i', '$1="#"', $html) ?? $html;

        return trim($html);
    }

    /** Markdown → sanitised HTML (Vanilla/NodeBB store post bodies as Markdown). */
    public static function markdown(?string $md): string
    {
        $md = (string) $md;
        if (trim($md) === '') {
            return '';
        }
        if (class_exists(\League\CommonMark\CommonMarkConverter::class)) {
            try {
                $conv = new \League\CommonMark\CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);

                return self::sanitizeHtml((string) $conv->convert($md));
            } catch (\Throwable) {
                // fall through to the plain-text rendering below
            }
        }

        return self::sanitizeHtml('<p>' . nl2br(htmlspecialchars($md, ENT_QUOTES), false) . '</p>');
    }

    /**
     * Unique tag slug from a name + source id. Flarum's `tags.slug` column is
     * `varchar(100)` — without strict SQL mode, MySQL silently truncates a
     * longer value, which can eat exactly the trailing `-$sourceId` that made
     * it unique (seen with vBulletin's folded, breadcrumb-suffixed secondary
     * tag names). Reserve room for that suffix up front instead.
     */
    public static function tagSlug(string $name, int $sourceId): string
    {
        $suffix = '-' . $sourceId;
        $slug = Str::slug($name) ?: 'tag';

        return Str::limit($slug, max(1, 100 - strlen($suffix)), '') . $suffix;
    }

    /**
     * Flarum usernames may not contain spaces or most punctuation. Turn a source
     * display name into a valid, reasonably-readable Flarum username.
     */
    public static function username(?string $name, int $sourceId): string
    {
        $u = preg_replace('/[^\w.-]+/u', '_', trim((string) $name));
        $u = trim((string) $u, '_.-');

        return $u !== '' ? $u : ('user' . $sourceId);
    }
}
