<?php

namespace ErnestDefoe\Importer;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Importers\Upload;
use Illuminate\Support\Carbon;

/**
 * The step-based import driver. An import is a sequence of {@see Importers\Phase}s;
 * each call to step() processes ONE bounded batch of the current phase and
 * returns quickly, so nothing runs long enough to hit a web timeout and no queue
 * worker is required. All state (phase, cursor, id-maps) lives in the DB, so a
 * run survives a closed tab or a killed request and can simply be resumed.
 */
class Runner
{
    /** Rows processed per step(). Small enough to finish well under any max_execution_time. */
    public const BATCH = 200;

    public static function start(string $source, array $cfg): array
    {
        $importer = Registry::get($source);
        if (! $importer) {
            throw new \RuntimeException('Unknown import source.');
        }
        $cfg = Upload::resolve($cfg); // an uploaded-dump run carries a {file} handle → sqlite
        Dst::reset();

        $phases = $importer::phases($cfg);
        $totals = [];
        $grand = 0;
        foreach ($phases as $ph) {
            $n = (int) ($ph->count)();
            $totals[$ph->key] = $n;
            $grand += $n;
        }

        $state = [
            'phaseIndex' => 0,
            'cursor' => null,
            'totals' => $totals,
            'grandTotal' => $grand,
            'processed' => 0,
            'summary' => [],
            'phaseLabel' => $phases[0]->label ?? 'Starting…',
        ];

        $id = (int) Dst::db()->table('importer_runs')->insertGetId([
            'source' => $source,
            'config' => json_encode($cfg),
            'state' => json_encode($state),
            'status' => 'running',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // If a real queue is configured, hand the run to a background worker so it
        // continues even if the admin closes the tab. The browser also drives
        // step()s — the DB lock keeps the two from double-processing — so this is
        // purely an accelerator; with no queue (SyncQueue) the browser does it all.
        try {
            $queue = resolve(\Illuminate\Contracts\Queue\Queue::class);
            if (! ($queue instanceof \Illuminate\Queue\SyncQueue)) {
                resolve(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(new \ErnestDefoe\Importer\Job\RunImportJob($id));
            }
        } catch (\Throwable) {
            // No usable queue — the browser step-loop handles it.
        }

        return self::progress($id, $state, 'running');
    }

    public static function step(int $runId): array
    {
        $run = Dst::db()->table('importer_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new \RuntimeException('Import run not found.');
        }
        if ($run->status !== 'running') {
            return self::progress($runId, json_decode($run->state, true) ?: [], $run->status, $run->error);
        }

        // Serialise steppers — a queue worker and the browser may both be driving
        // this run. Atomically claim the lock (stale after 30s in case a stepper died).
        $got = Dst::db()->table('importer_runs')->where('id', $runId)
            ->where(function ($q) {
                $q->whereNull('locked_at')->orWhere('locked_at', '<', Carbon::now()->subSeconds(30));
            })
            ->update(['locked_at' => Carbon::now()]);
        if (! $got) {
            return self::progress($runId, json_decode($run->state, true) ?: [], 'running') + ['skipped' => true];
        }

        $state = [];
        try {
            // Fresh read now that we hold the lock.
            $run = Dst::db()->table('importer_runs')->where('id', $runId)->first();
            if ($run->status !== 'running') {
                return self::progress($runId, json_decode($run->state, true) ?: [], $run->status, $run->error);
            }
            $cfg = json_decode($run->config ?: '{}', true) ?: [];
            $state = json_decode($run->state, true) ?: [];
            $importer = Registry::get($run->source);
            if (! $importer) {
                return self::finishFailed($runId, $state, 'Unknown import source.');
            }

            Dst::reset();
            $ctx = new Ctx($runId, $cfg);
            $phases = $importer::phases($cfg);
            $i = (int) ($state['phaseIndex'] ?? 0);

            if ($i >= count($phases)) {
                self::save($runId, $state, 'done', null, true);

                return self::progress($runId, $state, 'done');
            }

            $phase = $phases[$i];
            $res = ($phase->batch)($state['cursor'] ?? null, self::BATCH, $ctx);

            foreach (($res['summary'] ?? []) as $k => $v) {
                $state['summary'][$k] = (int) ($state['summary'][$k] ?? 0) + (int) $v;
            }
            $state['processed'] = (int) ($state['processed'] ?? 0) + (int) ($res['processed'] ?? 0);
            $state['cursor'] = $res['cursor'] ?? null;
            $state['phaseLabel'] = $phase->label;

            if (! empty($res['done'])) {
                $state['phaseIndex'] = $i + 1;
                $state['cursor'] = null;
            }

            $finished = $state['phaseIndex'] >= count($phases);
            self::save($runId, $state, $finished ? 'done' : 'running', null, $finished);

            return self::progress($runId, $state, $finished ? 'done' : 'running');
        } catch (\Throwable $e) {
            return self::finishFailed($runId, $state, $e->getMessage());
        } finally {
            Dst::db()->table('importer_runs')->where('id', $runId)->update(['locked_at' => null]);
        }
    }

    public static function status(int $runId): array
    {
        $run = Dst::db()->table('importer_runs')->where('id', $runId)->first();
        if (! $run) {
            return ['runId' => null, 'running' => false, 'percent' => 0, 'status' => null, 'summary' => []];
        }

        return self::progress($runId, json_decode($run->state, true) ?: [], $run->status, $run->error);
    }

    /** The newest run (so the admin page can detect + resume an import in progress). */
    public static function latest(): ?array
    {
        $run = Dst::db()->table('importer_runs')->orderByDesc('id')->first();

        return $run ? self::progress((int) $run->id, json_decode($run->state, true) ?: [], $run->status, $run->error) : null;
    }

    public static function reset(int $runId): void
    {
        $run = Dst::db()->table('importer_runs')->where('id', $runId)->first();
        if ($run && $run->config) {
            Upload::discard(json_decode($run->config, true) ?: []); // remove the scratch sqlite, if any
        }
        Dst::db()->table('importer_map')->where('run_id', $runId)->delete();
        Dst::db()->table('importer_runs')->where('id', $runId)->delete();
    }

    private static function finishFailed(int $runId, array $state, string $error): array
    {
        self::save($runId, $state, 'failed', $error, true);

        return self::progress($runId, $state, 'failed', $error);
    }

    private static function save(int $runId, array $state, string $status, ?string $error = null, bool $wipeConfig = false): void
    {
        $upd = ['state' => json_encode($state), 'status' => $status, 'updated_at' => Carbon::now()];
        if ($error !== null) {
            $upd['error'] = $error;
        }
        if ($wipeConfig) {
            $upd['config'] = null; // don't keep source-DB credentials after the run ends
        }
        Dst::db()->table('importer_runs')->where('id', $runId)->update($upd);
    }

    private static function progress(int $runId, array $state, string $status, ?string $error = null): array
    {
        $grand = max(1, (int) ($state['grandTotal'] ?? 1));
        $pct = match ($status) {
            'done' => 100,
            'failed' => (int) round(min(99, ($state['processed'] ?? 0) / $grand * 100)),
            default => (int) round(min(99, ($state['processed'] ?? 0) / $grand * 100)),
        };
        $summary = $state['summary'] ?? [];

        return [
            'runId' => $runId,
            'running' => $status === 'running',
            'done' => $status === 'done',
            'failed' => $status === 'failed',
            'percent' => $pct,
            'status' => $status === 'failed' ? ('Import failed: ' . $error) : ($status === 'done' ? 'Import complete.' : ($state['phaseLabel'] ?? 'Working…')),
            'summary' => $summary,
            'source' => null,
            'lastStatus' => $status === 'done' ? self::summaryLine($summary) : ($status === 'failed' ? ('Import failed: ' . $error) : null),
        ];
    }

    private static function summaryLine(array $s): string
    {
        return 'Imported ' . ($s['topics'] ?? 0) . ' discussions, ' . ($s['posts'] ?? 0) . ' posts, '
            . ($s['users'] ?? 0) . ' members' . (isset($s['categories']) ? ', ' . $s['categories'] . ' tags' : '') . '.';
    }
}
