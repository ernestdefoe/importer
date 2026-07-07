<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * Per-step context handed to a phase's batch closure: the source connection and
 * the source→Flarum id maps (persisted in `importer_map` so they survive across
 * the many short requests an import runs over). Writes go through {@see Dst}.
 */
class Ctx
{
    private $src = null;

    public function __construct(public int $runId, public array $cfg) {}

    /** The source database connection (cached for this request). */
    public function src()
    {
        return $this->src ??= Src::connect($this->cfg);
    }

    /** Record source→target id mappings (batched insert). */
    public function mapPut(string $kind, array $srcToTarget): void
    {
        if (! $srcToTarget) {
            return;
        }
        $rows = [];
        foreach ($srcToTarget as $srcId => $targetId) {
            $rows[] = ['run_id' => $this->runId, 'kind' => $kind, 'source_id' => (string) $srcId, 'target_id' => (int) $targetId];
        }
        // Chunk to stay well under any prepared-statement / packet limits.
        foreach (array_chunk($rows, 500) as $chunk) {
            Dst::db()->table('importer_map')->insert($chunk);
        }
    }

    /**
     * Resolve a set of source ids to their Flarum ids for one batch.
     *
     * @return array<string,int>  source_id => target_id
     */
    public function mapGet(string $kind, array $srcIds): array
    {
        $srcIds = array_values(array_unique(array_filter(array_map('strval', $srcIds), fn ($v) => $v !== '')));
        if (! $srcIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($srcIds, 1000) as $chunk) {
            $rows = Dst::db()->table('importer_map')
                ->where('run_id', $this->runId)->where('kind', $kind)
                ->whereIn('source_id', $chunk)
                ->get(['source_id', 'target_id']);
            foreach ($rows as $r) {
                $out[(string) $r->source_id] = (int) $r->target_id;
            }
        }

        return $out;
    }
}
