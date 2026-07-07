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
        $dir = resolve(Paths::class)->storage . '/imports';
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
            $cfg['database'] = self::dir() . '/' . basename((string) $cfg['file']);
        }

        return $cfg;
    }

    /** Delete a run's scratch sqlite (best effort). */
    public static function discard(array $cfg): void
    {
        $db = (string) ($cfg['database'] ?? '');
        if ($db !== '' && str_starts_with($db, self::dir()) && is_file($db)) {
            @unlink($db);
        }
    }

    /** Sweep scratch files older than a day so failed/abandoned uploads don't pile up. */
    public static function sweep(): void
    {
        foreach (glob(self::dir() . '/{scratch-*,dump-*}', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
