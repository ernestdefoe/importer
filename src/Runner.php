<?php

namespace ErnestDefoe\Importer;

use ErnestDefoe\Importer\Importers\Ctx;
use ErnestDefoe\Importer\Importers\Dst;
use ErnestDefoe\Importer\Importers\Registry;
use ErnestDefoe\Importer\Importers\Src;
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
            throw new \RuntimeException(Src::t('unknown_source'));
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
            'phaseLabel' => $phases[0]->label ?? Src::t('starting'),
            // Text written by the 0.3.7 converter: importer:repair-text skips it.
            'textFixed' => true,
        ];

        $id = self::create($source, $cfg, $state);
        self::dispatch($id);

        return self::progress($id, $state, 'running');
    }

    /**
     * Start a SYNC: bring across only what the old board has gained since the
     * import it continues (and, where the source records edit times, the
     * edits). Same phases, same step() loop as an import; the differences
     * live in the state:
     *
     *  - every id-map read and write goes to the base import's map, so a post
     *    in an old topic lands in the discussion that topic became, and the
     *    redirects pick the new discussions up with nothing else to do;
     *  - each phase starts at the highest source id that map holds (topics
     *    and posts a little before it, to catch rows approved late), so the
     *    old board is not re-read from the start;
     *  - nothing that was not imported is ever touched: content written on
     *    this forum is not in the map, so no phase can reach it.
     *
     * @param  array  $override  connection settings to use instead of the stored ones (the password, at least, when it was wiped)
     */
    public static function sync(array $override = [], bool $dispatch = true): array
    {
        $base = self::syncBase();
        if (! $base) {
            throw new \RuntimeException(Src::t('sync.no_base'));
        }
        $importer = Registry::get($base->source);
        $label = Registry::catalog()[$base->source]['label'] ?? $base->source;
        if (! $importer || ! Registry::syncable($base->source)) {
            throw new \RuntimeException(Src::t('sync.unsupported', ['source' => $label]));
        }
        $cfg = Upload::resolve(self::syncConfig(self::storedConfig((int) $base->id), $override));
        Dst::reset();

        $phases = $importer::phases($cfg);
        if (! array_filter($phases, fn ($ph) => $ph->syncable)) {
            throw new \RuntimeException(Src::t('sync.unsupported', ['source' => $label])); // vBulletin 5, reached through 'vbulletin'
        }

        $hw = [];
        foreach (['tag', 'user', 'topic', 'post'] as $kind) {
            $hw[$kind] = self::highWater((int) $base->id, $kind);
        }
        $last = self::chain((int) $base->id)->where('status', 'done')->orderByDesc('id')->value('created_at');
        $sync = [
            'base' => (int) $base->id,
            // Where each phase starts. 0 posts = an import from before posts were
            // mapped: one full pass that adopts what is here and adds the rest.
            'from' => [
                'tags' => $hw['tag'],
                'users' => $hw['user'],
                'topics' => max(0, $hw['topic'] - self::CATCHUP),
                'posts' => $hw['post'] ? max(0, $hw['post'] - self::CATCHUP) : 0,
            ],
            // Edits since the previous run began, with an hour's slack for clocks.
            'since' => $last ? Carbon::parse($last)->getTimestamp() - 3600 : 0,
            // Everything above this was written by this sync (or by members
            // meanwhile): the recounts look no further than that.
            'postHw' => (int) Dst::db()->table('posts')->max('id'),
        ];

        $state = [
            'phaseIndex' => 0,
            'cursor' => $sync['from'][$phases[0]->key] ?? null,
            'totals' => [],
            'grandTotal' => 0,
            'phaseCount' => count($phases),
            'processed' => 0,
            'summary' => [],
            'phaseLabel' => Src::t('phase.sync'),
            'textFixed' => true,
            'sync' => $sync,
        ];

        $id = self::create($base->source, $cfg, $state, (int) $base->id);
        if ($dispatch) {
            self::dispatch($id);
        }

        return self::progress($id, $state, 'running');
    }

    /**
     * What the wizard needs to offer a sync: the import it would continue and
     * the connection it would reuse — never the password, which is not kept.
     */
    public static function syncInfo(): ?array
    {
        $base = self::syncBase();
        if (! $base || ! Registry::syncable($base->source)) {
            return null;
        }
        $cfg = self::storedConfig((int) $base->id);
        $upload = ! empty($cfg['file']);
        $keys = $upload ? ['prefix'] : ['driver', 'host', 'port', 'database', 'username', 'prefix', 'assets_base'];

        return [
            'baseRunId' => (int) $base->id,
            'source' => $base->source,
            'config' => array_intersect_key($cfg, array_flip($keys)),
            'needsPassword' => ! empty($cfg['password_wiped']) || ! $cfg,
            'upload' => $upload,
            'last' => (string) self::chain((int) $base->id)->where('status', 'done')->orderByDesc('id')->value('updated_at'),
        ];
    }

    /** Rows re-read below a high-water mark, for content approved after it was passed. */
    private const CATCHUP = 500;

    /** The newest finished import (not a sync) — the one a sync continues. */
    private static function syncBase(): ?object
    {
        return Dst::db()->table('importer_runs')->whereNull('base_run_id')->where('status', 'done')->orderByDesc('id')->first();
    }

    private static function chain(int $baseId): \Illuminate\Database\Query\Builder
    {
        return Dst::db()->table('importer_runs')->where(fn ($q) => $q->where('id', $baseId)->orWhere('base_run_id', $baseId));
    }

    /** The connection the last run in the chain used, as kept after it finished (no password). */
    private static function storedConfig(int $baseId): array
    {
        return json_decode((string) self::chain($baseId)->whereNotNull('config')->orderByDesc('id')->value('config'), true) ?: [];
    }

    /** Stored settings, overlaid with whatever was supplied now; refuses when what is missing cannot be guessed. */
    private static function syncConfig(array $stored, array $override): array
    {
        $override = array_filter($override, fn ($v) => $v !== null && $v !== '');
        if (! empty($stored['file']) && empty($override['file'])) {
            throw new \RuntimeException(Src::t('sync.needs_upload'));
        }
        if (! empty($override['file'])) {
            unset($stored['database'], $stored['driver']);
        }
        $cfg = $override + $stored;
        if (empty($cfg['file']) && empty($cfg['database'])) {
            throw new \RuntimeException(Src::t('sync.needs_connection'));
        }
        if (! empty($stored['password_wiped']) && empty($override['password'])) {
            throw new \RuntimeException(Src::t('sync.needs_password'));
        }
        unset($cfg['password_wiped']);

        return $cfg;
    }

    /** The highest source id mapped for a kind (ids are numeric for every source that syncs). */
    private static function highWater(int $runId, string $kind): int
    {
        // LENGTH first: source_id is a string, and '99' sorts above '100'.
        return (int) Dst::db()->table('importer_map')->where('run_id', $runId)->where('kind', $kind)
            ->orderByRaw('LENGTH(source_id) DESC')->orderByDesc('source_id')->value('source_id');
    }

    /**
     * Insert a run — refused while another is running. Two runs writing at
     * once would number posts in the same discussion against each other.
     */
    private static function create(string $source, array $cfg, array $state, ?int $baseRunId = null): int
    {
        return Dst::db()->transaction(function ($db) use ($source, $cfg, $state, $baseRunId) {
            if ($busy = $db->table('importer_runs')->where('status', 'running')->value('id')) {
                throw new \RuntimeException(Src::t('busy', ['id' => $busy]));
            }

            return (int) $db->table('importer_runs')->insertGetId([
                'source' => $source,
                'config' => json_encode($cfg),
                'state' => json_encode($state),
                'status' => 'running',
                'base_run_id' => $baseRunId,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * If a real queue is configured, hand the run to a background worker so it
     * continues even if the admin closes the tab. The browser also drives
     * step()s — the DB lock keeps the two from double-processing — so this is
     * purely an accelerator; with no queue (SyncQueue) the browser does it all.
     */
    private static function dispatch(int $id): void
    {
        try {
            $queue = resolve(\Illuminate\Contracts\Queue\Queue::class);
            if (! ($queue instanceof \Illuminate\Queue\SyncQueue)) {
                resolve(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(new \ErnestDefoe\Importer\Job\RunImportJob($id));
            }
        } catch (\Throwable) {
            // No usable queue — the browser step-loop handles it.
        }
    }

    public static function step(int $runId): array
    {
        $run = Dst::db()->table('importer_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new \RuntimeException(Src::t('run_not_found'));
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
                return self::finishFailed($runId, $state, Src::t('unknown_source'));
            }

            Dst::reset();
            $ctx = new Ctx((int) ($state['sync']['base'] ?? $runId), $cfg, $state['sync'] ?? null);
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
                $state['cursor'] = $state['sync']['from'][$phases[$i + 1]->key ?? ''] ?? null;
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
        // An import's syncs go with it: their content is in its map.
        Dst::db()->table('importer_runs')->where('id', $runId)->orWhere('base_run_id', $runId)->delete();
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
            // Never keep the source database's password once the run ends. The
            // rest stays, so a sync can reuse it and ask for the password alone.
            $cfg = json_decode((string) Dst::db()->table('importer_runs')->where('id', $runId)->value('config'), true) ?: [];
            if (($cfg['password'] ?? '') !== '') {
                $cfg['password_wiped'] = true;
            }
            unset($cfg['password']);
            $upd['config'] = $cfg ? json_encode($cfg) : null;
        }
        Dst::db()->table('importer_runs')->where('id', $runId)->update($upd);
    }

    private static function progress(int $runId, array $state, string $status, ?string $error = null): array
    {
        $sync = isset($state['sync']);
        // A sync cannot know its size up front, so its bar moves by phase.
        $pct = $status === 'done' ? 100 : (int) round(min(99, $sync
            ? ($state['phaseIndex'] ?? 0) / max(1, (int) ($state['phaseCount'] ?? 1)) * 100
            : ($state['processed'] ?? 0) / max(1, (int) ($state['grandTotal'] ?? 1)) * 100));
        $summary = $state['summary'] ?? [];

        return [
            'runId' => $runId,
            'running' => $status === 'running',
            'done' => $status === 'done',
            'failed' => $status === 'failed',
            'percent' => $pct,
            'status' => $status === 'failed' ? Src::t('failed', ['error' => $error]) : ($status === 'done' ? Src::t('complete') : ($state['phaseLabel'] ?? Src::t('working'))),
            'summary' => $summary,
            'source' => null,
            'sync' => $sync,
            'lastStatus' => $status === 'done' ? self::summaryLine($summary, $sync) : ($status === 'failed' ? Src::t('failed', ['error' => $error]) : null),
        ];
    }

    private static function summaryLine(array $s, bool $sync = false): string
    {
        $params = ['topics' => $s['topics'] ?? 0, 'posts' => $s['posts'] ?? 0, 'users' => $s['users'] ?? 0, 'categories' => $s['categories'] ?? 0];
        if ($sync) {
            return Src::t('sync.summary', $params + ['edited' => $s['edited'] ?? 0, 'kept' => $s['kept'] ?? 0]);
        }

        return Src::t(isset($s['categories']) ? 'summary_with_tags' : 'summary', $params);
    }
}
