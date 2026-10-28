<?php

namespace ErnestDefoe\Importer\Importers;

use Flarum\Foundation\Paths;

/**
 * Scratch storage for uploaded database dumps. An upload is converted to a
 * SQLite file under storage/imports; the run then reads it via the sqlite
 * driver. Only a bare filename ("handle") ever crosses to the client, so a
 * request can't point the importer at an arbitrary path.
 */
class Upload
{
    public static function dir(): string
    {
        $dir = resolve(Paths::class)->storage.'/imports';
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }

    /** Turn an {file: handle} config (from an upload) into a concrete sqlite config. */
    public static function resolve(array $cfg): array
    {
        if (! empty($cfg['file'])) {
            $cfg['driver'] = 'sqlite';
            $cfg['database'] = self::dir().'/'.basename((string) $cfg['file']);
        }

        return $cfg;
    }

    /**
     * Delete a run's scratch sqlite (best effort): a full copy of the old
     * board, members' emails and password hashes included.
     *
     * 🚨 Only a file really inside the scratch directory. A prefix check on the
     * path as written let `imports/../../config.php` through.
     */
    public static function discard(array $cfg): void
    {
        $db = realpath((string) ($cfg['database'] ?? ''));
        $dir = realpath(self::dir());
        if ($db && $dir && str_starts_with($db, $dir.DIRECTORY_SEPARATOR) && is_file($db)) {
            @unlink($db);
        }
    }

    /**
     * Sweep scratch files older than a day so failed/abandoned uploads don't
     * pile up — never the one a running import is reading.
     */
    public static function sweep(): void
    {
        $inUse = [];
        foreach (Dst::db()->table('importer_runs')->where('status', 'running')->pluck('config') as $cfg) {
            $file = (string) (json_decode((string) $cfg, true)['file'] ?? '');
            if ($file !== '') {
                $inUse[basename($file)] = true;
            }
        }
        foreach (glob(self::dir().'/{scratch-*,dump-*}', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400 && ! isset($inUse[basename($f)])) {
                @unlink($f);
            }
        }
    }
}
