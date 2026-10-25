<?php

namespace ErnestDefoe\Importer\Console;

use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\PrivateMessages;
use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Importers\Src;
use ErnestDefoe\Importer\Runner;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Headless import — drives the same step engine the admin wizard uses, in a
 * loop. Handy on hosts that do have SSH (and for very large migrations run
 * inside screen/tmux). On shared hosting without SSH, use the admin panel; the
 * engine is identical.
 */
class RunImportCommand extends AbstractCommand
{
    /** Safety net so a stuck run can't spin the CLI forever. Matches RunImportJob. */
    private const MAX_STEPS = 5_000_000;

    protected function configure(): void
    {
        $this
            ->setName('importer:run')
            ->setDescription('Import a forum into Flarum from another platform.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source platform: '.implode(', ', array_keys(Registry::map())))
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'DB driver (mysql|pgsql|sqlite)', 'mysql')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'DB host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'DB port', '')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'DB name', '')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'DB username', 'root')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'DB password', '')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Source table prefix', '')
            ->addOption('assets-base', null, InputOption::VALUE_REQUIRED, 'Origin for RELATIVE image paths on the old board, e.g. https://old.example.com. Absolute URLs are used as they are; without this, relative ones are skipped.', '')
            ->addOption('avatar-path', null, InputOption::VALUE_REQUIRED, 'Local filesystem path to the source forum\'s avatar directory (vBulletin customavatars/, etc.) — only needed when avatars aren\'t stored as DB blobs.', '')
            ->addOption('prune-empty-tags', null, InputOption::VALUE_NONE, 'After import, delete tags this run created that ended up with zero discussions and no children (e.g. an empty vBulletin sub-forum). Never touches pre-existing tags.')
            ->addOption('max-topics', null, InputOption::VALUE_REQUIRED, 'Import at most this many topics (oldest-id-first) instead of the whole forum — a fast, representative subset for testing a patch or a fresh source connection before committing to a full run.', '')
            ->addOption('no-private-messages', null, InputOption::VALUE_NONE, 'Leave private messages behind. They come across by default when flarum/messages is enabled (conversations of three or more people need ernestdefoe/group-messages).')
            ->addOption('test', null, InputOption::VALUE_NONE, 'Only test the connection + show counts.')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Bring across only what the old forum gained since the last import (and edits, where the source records them). Reuses that import\'s connection settings; pass any that changed, and --password if the source has one.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --sync: count what would be imported, then roll it all back.');
    }

    protected function fire(): int
    {
        if ($this->input->getOption('sync')) {
            return $this->sync();
        }
        if ($this->input->getOption('dry-run')) {
            $this->error('--dry-run works with --sync.');

            return 1;
        }

        $source = (string) $this->input->getOption('source');
        $importer = Registry::get($source);
        if (! $importer) {
            $this->error('Unknown source "'.$source.'". Available: '.implode(', ', array_keys(Registry::map())));

            return 1;
        }

        $cfg = [];
        foreach (self::CONNECTION as $opt => $key) {
            $cfg[$key] = (string) $this->input->getOption($opt);
        }
        $cfg['avatar_path'] = (string) $this->input->getOption('avatar-path');
        $cfg['prune_empty_tags'] = (bool) $this->input->getOption('prune-empty-tags');
        $cfg['max_topics'] = (string) $this->input->getOption('max-topics');
        $cfg['private_messages'] = $this->input->getOption('no-private-messages') ? '0' : '1';

        try {
            if ($this->input->getOption('test')) {
                $r = $importer::test($cfg);
                $pms = PrivateMessages::count($importer, $cfg);
                $this->info('Connection OK — '.json_encode(($r['counts'] ?? []) + ($pms !== null ? ['messages' => $pms] : [])));

                return 0;
            }

            return $this->drive(Runner::start($source, $cfg), 'Import complete — ');
        } catch (\Throwable $e) {
            $this->error('Import failed: '.Src::explain($e));

            return 1;
        }
    }

    /** CLI option => config key, for the source connection. */
    private const CONNECTION = ['driver' => 'driver', 'host' => 'host', 'port' => 'port', 'database' => 'database', 'username' => 'username', 'password' => 'password', 'prefix' => 'prefix', 'assets-base' => 'assets_base'];

    private function sync(): int
    {
        // Only the options actually typed: everything else comes from the last run.
        $cfg = [];
        foreach (self::CONNECTION as $opt => $key) {
            if ($this->input->hasParameterOption('--'.$opt)) {
                $cfg[$key] = (string) $this->input->getOption($opt);
            }
        }
        if ($this->input->getOption('no-private-messages')) {
            $cfg['private_messages'] = '0';
        }
        $dry = (bool) $this->input->getOption('dry-run');
        $db = resolve(ConnectionInterface::class);

        if ($dry) {
            Dst::$dryRun = true;
            $db->beginTransaction();
        }

        try {
            $info = Runner::syncInfo();
            $source = (string) $this->input->getOption('source');
            if ($info && $source !== '' && $source !== $info['source']) {
                $this->error("The last import came from {$info['source']}, not {$source}. A sync continues that import.");

                return 1;
            }

            return $this->drive(Runner::sync($cfg, ! $dry), $dry ? 'Dry run — would bring across: ' : 'Sync complete — ', $dry);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.Src::explain($e));

            return 1;
        } finally {
            if ($dry) {
                $db->rollBack();
                $this->info('Dry run: nothing was saved.');
            }
        }
    }

    private function drive(array $start, string $donePrefix, bool $quiet = false): int
    {
        $runId = (int) $start['runId'];
        $last = -1;
        // Same generous cap the queued job uses (200 rows/step → up to 1e9
        // rows), so a state machine that never reaches done/failed can't spin
        // this process forever.
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $st = Runner::step($runId);
            if (! $quiet && $st['percent'] !== $last) {
                $this->info(sprintf('[%3d%%] %s', $st['percent'], $st['status']));
                $last = $st['percent'];
            }
            if (! empty($st['failed'])) {
                $this->error($st['status']);

                return 1;
            }
            if (! empty($st['done'])) {
                $this->info($donePrefix.($st['lastStatus'] ?? ''));

                return 0;
            }
        }

        $this->error(sprintf(
            'Import gave up after %s steps without finishing — run %d may be stuck. Check its status/error in the importer_runs table, or reset it from Admin → Importer.',
            number_format(self::MAX_STEPS),
            $runId
        ));

        return 1;
    }
}
