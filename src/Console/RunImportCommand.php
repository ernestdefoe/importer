<?php

namespace ErnestDefoe\Importer\Console;

use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Runner;
use Flarum\Console\AbstractCommand;
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
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source platform: ' . implode(', ', array_keys(Registry::map())))
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'DB driver (mysql|pgsql|sqlite)', 'mysql')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'DB host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'DB port', '')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'DB name', '')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'DB username', 'root')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'DB password', '')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Source table prefix', '')
            ->addOption('test', null, InputOption::VALUE_NONE, 'Only test the connection + show counts.');
    }

    protected function fire(): int
    {
        $source = (string) $this->input->getOption('source');
        $importer = Registry::get($source);
        if (! $importer) {
            $this->error('Unknown source "' . $source . '". Available: ' . implode(', ', array_keys(Registry::map())));

            return 1;
        }

        $cfg = [
            'driver' => (string) $this->input->getOption('driver'),
            'host' => (string) $this->input->getOption('host'),
            'port' => (string) $this->input->getOption('port'),
            'database' => (string) $this->input->getOption('database'),
            'username' => (string) $this->input->getOption('username'),
            'password' => (string) $this->input->getOption('password'),
            'prefix' => (string) $this->input->getOption('prefix'),
        ];

        try {
            if ($this->input->getOption('test')) {
                $r = $importer::test($cfg);
                $this->info('Connection OK — ' . json_encode($r['counts'] ?? []));

                return 0;
            }

            $start = Runner::start($source, $cfg);
            $runId = (int) $start['runId'];
            $last = -1;
            // Same generous cap the queued job uses (200 rows/step → up to 1e9
            // rows), so a state machine that never reaches done/failed can't spin
            // this process forever.
            for ($i = 0; $i < self::MAX_STEPS; $i++) {
                $st = Runner::step($runId);
                if ($st['percent'] !== $last) {
                    $this->info(sprintf('[%3d%%] %s', $st['percent'], $st['status']));
                    $last = $st['percent'];
                }
                if (! empty($st['failed'])) {
                    $this->error($st['status']);

                    return 1;
                }
                if (! empty($st['done'])) {
                    $this->info('Import complete — ' . ($st['lastStatus'] ?? ''));

                    return 0;
                }
            }

            $this->error(sprintf(
                'Import gave up after %s steps without finishing — run %d may be stuck. Check its status/error in the importer_runs table, or reset it from Admin → Importer.',
                number_format(self::MAX_STEPS),
                $runId
            ));

            return 1;
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());

            return 1;
        }
    }
}
