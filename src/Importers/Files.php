<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * The bytes of a file the old board kept: an avatar, an attachment. Each
 * importer says where a file is in up to three ways, tried in this order:
 *
 *   bytes  the database holds it (a BLOB), or a closure that reads it
 *   path   relative to the old board's upload folder (`root`) on THIS server (`files_path`,
 *          importer:run --files-path): what an operator who copied the old
 *          site across, or still runs it here, has
 *   url    relative to the old board's address (`assets_base`), or absolute:
 *          fetched with {@see SafeFetch}, for files the old board serves
 *
 * 🚨 A path comes out of the old board's database, so it is never trusted:
 * it must resolve to a regular file INSIDE the folder the operator named
 * (no `..`, no symlink out of it), or it is not read.
 */
final class Files
{
    /**
     * @param  array{bytes?:string|\Closure|null,root?:string,dir?:string,path?:string|list<string>|null,url?:?string}  $item
     *         root: the board's upload folder under `files_path`; dir: an absolute folder instead
     */
    public static function bytes(array $item, array $cfg, int $max, int $timeout = 20): ?string
    {
        $bytes = $item['bytes'] ?? null;
        if ($bytes instanceof \Closure) {
            $bytes = $bytes();
        }
        if (is_string($bytes) && $bytes !== '') {
            return strlen($bytes) <= $max ? $bytes : null;
        }
        $root = trim((string) ($cfg['files_path'] ?? ''));
        if (isset($item['dir']) && $item['dir'] !== '') { // a folder of its own, absolute (vBulletin's avatar_path)
            $root = (string) $item['dir'];
        } elseif ($root !== '' && ($item['root'] ?? '') !== '') {
            $root = rtrim($root, '/\\').'/'.trim((string) $item['root'], '/\\');
        }
        foreach ((array) ($item['path'] ?? []) as $path) { // a list: where it is under each layout the board has used
            if ((string) $path !== '' && ($read = self::read($root, (string) $path, $max)) !== null) {
                return $read;
            }
        }
        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $base = rtrim(trim((string) ($cfg['assets_base'] ?? '')), '/');
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            if ($base === '') {
                return null;
            }
            $url = $base.'/'.ltrim($url, './');
        }

        return SafeFetch::get($url, $max, $timeout, (string) parse_url($base, PHP_URL_HOST));
    }

    /**
     * Where a folder the old board names in its settings is: the folder itself
     * when this server has it (the board still runs here), else the folder of
     * that name under `files_path`.
     *
     * @return array{root?:string,dir?:string}
     */
    public static function folder(?string $setting, string $default): array
    {
        $setting = str_replace('\\', '/', trim((string) $setting));
        $absolute = str_starts_with($setting, '/') || preg_match('#^[a-z]:/#i', $setting);
        if ($absolute && is_dir($setting)) {
            return ['dir' => $setting];
        }
        $rel = trim((string) preg_replace('#^\./#', '', $setting), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return ['root' => $default];
        }

        // An absolute folder on the old server: the folder of that name in the copy here.
        return ['root' => $absolute ? basename($rel) : $rel];
    }

    /** Names never read, wherever they are: the old board's settings, scripts and server files. */
    private const NEVER = '/(?:\.(?:php\d?|phtml|phar|inc|env|ini|cnf|conf|htaccess|htpasswd|sql|sh|key|pem)$|^\.|^(?:config|settings|conf_global|wp-config)\b)/i';

    /**
     * A file under $root, or null when it is outside it, missing, larger than
     * $max, or one of {@see NEVER}. Each importer names the board's upload
     * folder itself as $root (phpBB's files/, XenForo's internal_data/…), so
     * a path in the database cannot reach the board's config beside it.
     */
    public static function read(string $root, string $rel, int $max): ?string
    {
        $root = rtrim(trim($root), '/\\');
        if ($root === '' || str_contains($root.$rel, "\0") || ($real = realpath($root)) === false || ! self::allowed($real)) {
            return null;
        }
        $file = realpath($real.'/'.ltrim(str_replace('\\', '/', $rel), '/'));
        if ($file === false || ! str_starts_with($file, $real.DIRECTORY_SEPARATOR) || preg_match(self::NEVER, basename($file))
            || ! is_file($file) || filesize($file) > $max) {
            return null;
        }
        $bytes = @file_get_contents($file);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    /** 🚨 Never this forum's own install, nor a folder above it (its config.php and storage). */
    private static function allowed(string $real): bool
    {
        if ($real === DIRECTORY_SEPARATOR) {
            return false;
        }
        try {
            $mine = realpath(resolve(\Flarum\Foundation\Paths::class)->base) ?: null;
        } catch (\Throwable) {
            $mine = null;
        }

        return $mine === null || ($real !== $mine && ! str_starts_with($real, $mine.DIRECTORY_SEPARATOR) && ! str_starts_with($mine, $real.DIRECTORY_SEPARATOR));
    }
}
