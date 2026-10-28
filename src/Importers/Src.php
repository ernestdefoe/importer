<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;
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

    /** Translate an `ernestdefoe-importer.api.*` string (these classes are static, so no injection). */
    public static function t(string $key, array $params = []): string
    {
        return resolve(\Flarum\Locale\TranslatorInterface::class)->trans('ernestdefoe-importer.api.'.$key, $params);
    }

    /**
     * Build a runtime read connection to the source database (MySQL, PostgreSQL
     * for Discourse, or SQLite for fixtures). Registers a named connection on
     * Flarum's DatabaseManager via the config repository (Flarum's `config()`
     * helper is read-only, so we set it on the repository directly).
     */
    public static function connect(array $cfg): ConnectionInterface
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
                throw new \RuntimeException(self::t('sqlsrv_missing'));
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
        $config->set('database.connections.'.self::CONN, $conf);

        /** @var \Illuminate\Database\DatabaseManager $db */
        $db = resolve('db');
        $db->purge(self::CONN);

        return $db->connection(self::CONN);
    }

    /**
     * Turn the PDO errors people actually hit when pointing at a remote source
     * database into the fix, keeping the driver's own text on the end. Anything
     * else passes through unchanged.
     */
    public static function explain(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $hint = match (true) {
            str_contains($msg, '[2002]') && stripos($msg, 'timed out') !== false => self::t('explain.timed_out'),
            str_contains($msg, '[2002]') => self::t('explain.no_answer'),
            str_contains($msg, '[1130]') => self::t('explain.host_refused'),
            str_contains($msg, '[1045]') => self::t('explain.bad_login'),
            default => null,
        };

        return $hint ? $hint.' ('.rtrim(strtok($msg, '(')).')' : $msg;
    }

    public static function color(?string $c): string
    {
        $c = trim((string) $c);

        return preg_match('/^#?[0-9a-fA-F]{6}$/', $c) ? (str_starts_with($c, '#') ? $c : '#'.$c) : '#5b5bd6';
    }

    /** Copy bcrypt hashes (Flarum uses bcrypt too, so they work straight away); anything else → random (user resets). */
    public static function password(?string $hash): string
    {
        $hash = (string) $hash;

        return preg_match('#^\$2[aby]\$#', $hash) ? $hash : password_hash(Str::random(24), PASSWORD_BCRYPT);
    }

    /**
     * A self-describing hash (phpBB, Vanilla) as a legacy password, or null
     * when it is bcrypt Flarum checks already (then it is copied as it is).
     *
     * @return array{scheme:string,hash:string,salt:?string}|null
     */
    public static function legacy(?string $hash, ?string $scheme = null, ?string $salt = null): ?array
    {
        $hash = trim((string) $hash);
        if ($hash === '') {
            return null;
        }
        $scheme ??= \ErnestDefoe\Importer\Auth\LegacyPassword::detect($hash);

        return $scheme === null ? null : ['scheme' => $scheme, 'hash' => $hash, 'salt' => $salt];
    }

    /** Unix timestamp or datetime string → Carbon. */
    public static function ts(mixed $v): Carbon
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

    /**
     * Markdown → HTML (Vanilla and NodeBB store post bodies as Markdown).
     *
     * GitHub-flavoured, as both boards are: tables, ~~strikethrough~~, and a
     * line break where the member pressed Enter. The HTML is made safe and
     * canonical later, in {@see Dst::content()}.
     *
     * @param  array{html?:bool,mentions?:bool}  $opts
     *   html      the board allowed HTML inside Markdown (Vanilla); otherwise it is shown as typed
     *   mentions  `@name` / `@"Full Name"` are the board's mentions
     */
    public static function markdown(?string $md, array $opts = []): string
    {
        $md = (string) $md;
        if (trim($md) === '') {
            return '';
        }
        $html = null;
        if (class_exists(\League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            try {
                $html = (string) (new \League\CommonMark\GithubFlavoredMarkdownConverter([
                    'html_input' => ! empty($opts['html']) ? 'allow' : 'escape',
                    'allow_unsafe_links' => false,
                    'renderer' => ['soft_break' => "<br>\n"],
                ]))->convert($md);
            } catch (\Throwable) {
                // fall through to the plain-text rendering below
            }
        }
        $html ??= '<p>'.nl2br(htmlspecialchars($md, ENT_QUOTES), false).'</p>';

        return ! empty($opts['mentions']) ? \ErnestDefoe\Importer\Markup\Html::textMentions($html) : $html;
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
        $suffix = '-'.$sourceId;
        $slug = Str::slug($name) ?: 'tag';

        return Str::limit($slug, max(1, 100 - strlen($suffix)), '').$suffix;
    }

    /**
     * A source display name as a valid Flarum username: ASCII letters, digits,
     * `_` and `-`, 3 to 30 characters (core's UserValidator, and what
     * flarum/mentions can match after an @).
     *
     * 🚨 `josé.p`, `Иван` and `Al` used to come across as they were: a
     * username Flarum itself would refuse, that nobody could @-mention and
     * that broke the next save of that member. Accents and other scripts are
     * transliterated (José → Jose, Иван → Ivan); the name as the old board
     * showed it is kept as the member's nickname where that is possible.
     */
    public static function username(?string $name, int $sourceId): string
    {
        $u = Str::ascii(html_entity_decode(trim((string) $name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $u = trim((string) preg_replace(['/[^A-Za-z0-9_-]+/', '/_{2,}/'], '_', $u), '_-');
        if ($u === '') {
            return 'user'.$sourceId;
        }
        if (strlen($u) < 3) {
            $u .= '_'.$sourceId;
        }

        return Str::limit(str_pad($u, 3, '_'), 30, '');
    }
}
