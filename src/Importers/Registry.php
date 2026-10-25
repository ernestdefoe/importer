<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Maps a source-platform key to its importer class. Each importer exposes two
 * static methods:
 *   test(array $cfg): array         → validate + row counts
 *   phases(array $cfg): Phase[]     → the batched, resumable import steps.
 */
class Registry
{
    public static function map(): array
    {
        return [
            'phpbb' => PhpbbImporter::class,
            'xenforo' => XenForoImporter::class,
            'vbulletin' => VbulletinImporter::class,   // 3/4, and delegates to 5/6 on the node schema
            'vbulletin5' => Vbulletin5Importer::class,
            'mybb' => MybbImporter::class,
            'smf' => SmfImporter::class,
            'vanilla' => VanillaImporter::class,
            'nodebb' => NodebbImporter::class,
            'invision' => InvisionImporter::class,
            'discourse' => DiscourseImporter::class,
            'convoro' => ConvoroImporter::class,
            'webwiz' => WebWizImporter::class,
        ];
    }

    /** Platforms shown in the wizard (mirror of the JS SOURCES map). */
    public static function catalog(): array
    {
        return [
            'phpbb' => ['label' => 'phpBB 3.x', 'driver' => 'mysql', 'prefix' => 'phpbb_'],
            'xenforo' => ['label' => 'XenForo 1.x / 2.x', 'driver' => 'mysql', 'prefix' => ''],
            'vbulletin' => ['label' => 'vBulletin 3.x / 4.x / 5.x', 'driver' => 'mysql', 'prefix' => ''],
            'mybb' => ['label' => 'MyBB 1.8.x', 'driver' => 'mysql', 'prefix' => 'mybb_'],
            'smf' => ['label' => 'SMF 2.0 / 2.1', 'driver' => 'mysql', 'prefix' => 'smf_'],
            'vanilla' => ['label' => 'Vanilla Forums', 'driver' => 'mysql', 'prefix' => 'GDN_'],
            'nodebb' => ['label' => 'NodeBB (Redis)', 'driver' => 'redis', 'prefix' => ''],
            'invision' => ['label' => 'Invision Community (IP.Board)', 'driver' => 'mysql', 'prefix' => ''],
            'discourse' => ['label' => 'Discourse (PostgreSQL)', 'driver' => 'pgsql', 'prefix' => ''],
            'convoro' => ['label' => 'Convoro', 'driver' => 'mysql', 'prefix' => ''],
            // Web Wiz runs on SQL Server. A direct connection needs pdo_sqlsrv,
            // which most hosts lack — the file-upload route is the usual path.
            'webwiz' => ['label' => 'Web Wiz Forums (SQL Server)', 'driver' => 'sqlsrv', 'prefix' => 'tbl'],
        ];
    }

    /**
     * Sources whose import can be kept in sync afterwards (their posts phase
     * is {@see Phases::posts()}). Vanilla, NodeBB and vBulletin 5 write the
     * first post in the topics phase, and cannot be synced yet; nor can a
     * vBulletin 5 board reached through 'vbulletin' (the runner checks).
     */
    public static function syncable(string $key): bool
    {
        return isset(self::map()[$key]) && ! in_array($key, ['vanilla', 'nodebb', 'vbulletin5'], true);
    }

    /**
     * An importer's phases, with the private-message phases on the end where
     * the source has messages. A sync that can only bring messages across
     * (`state.sync.only`) runs just those.
     *
     * @return Phase[]
     */
    public static function phases(string $key, array $cfg, array $state = []): array
    {
        $importer = self::get($key);
        if (! $importer) {
            return [];
        }
        /** @var Phase[] $phases */
        $phases = array_merge($importer::phases($cfg), PrivateMessages::phases($importer, $cfg));
        $only = (array) ($state['sync']['only'] ?? []);

        return $only ? array_values(array_filter($phases, fn (Phase $ph) => in_array($ph->key, $only, true))) : $phases;
    }

    public static function get(string $key): ?string
    {
        return self::map()[$key] ?? null;
    }
}
