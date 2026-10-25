<?php

namespace ErnestDefoe\Importer\Importers;

use Illuminate\Database\ConnectionInterface;

/**
 * Per-step context handed to a phase's batch closure: the source connection and
 * the source→Flarum id maps (persisted in `importer_map` so they survive across
 * the many short requests an import runs over). Writes go through {@see Dst}.
 */
class Ctx
{
    private ?ConnectionInterface $src = null;

    /**
     * @param  int  $runId  the run whose id map this step reads and writes —
     *                      for a sync, the import it continues, so one map
     *                      holds everything that board has ever sent here
     * @param  array|null  $sync  set on a sync run: where each phase starts,
     *                            the edit cut-off and the Flarum post id
     *                            high-water (see {@see \ErnestDefoe\Importer\Runner::sync()})
     */
    public function __construct(public int $runId, public array $cfg, public ?array $sync = null)
    {
    }

    /** The source database connection (cached for this request). */
    public function src(): ConnectionInterface
    {
        return $this->src ??= Src::connect($this->cfg);
    }

    /**
     * Record source→target id mappings (batched insert).
     *
     * @param  array<string,string>  $hashes  source_id => sha1 of the content written (posts)
     */
    public function mapPut(string $kind, array $srcToTarget, array $hashes = []): void
    {
        if (! $srcToTarget) {
            return;
        }
        $rows = [];
        foreach ($srcToTarget as $srcId => $targetId) {
            $rows[] = ['run_id' => $this->runId, 'kind' => $kind, 'source_id' => (string) $srcId, 'target_id' => (int) $targetId, 'hash' => $hashes[$srcId] ?? null];
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
        return array_map(fn ($r) => $r[0], $this->mapRows($kind, $srcIds));
    }

    /**
     * {@see mapGet()} for several kinds at once: one query (a few on a very
     * large batch) where a call per kind would be one each.
     *
     * @param  array<string,array>  $want  kind => source ids
     * @return array<string,array<string,int>>  kind => [source_id => target_id]
     */
    public function mapMany(array $want): array
    {
        $out = array_fill_keys(array_keys($want), []);
        $pairs = [];
        foreach ($want as $kind => $ids) {
            foreach (array_unique(array_filter(array_map('strval', $ids), fn ($v) => $v !== '')) as $id) {
                $pairs[] = [$kind, $id];
            }
        }
        // Well under SQLite's 999 bound parameters per statement.
        foreach (array_chunk($pairs, 800) as $chunk) {
            $byKind = [];
            foreach ($chunk as [$kind, $id]) {
                $byKind[$kind][] = $id;
            }
            $q = Dst::db()->table('importer_map')->where('run_id', $this->runId)->where(function ($w) use ($byKind) {
                foreach ($byKind as $kind => $ids) {
                    $w->orWhere(fn ($k) => $k->where('kind', $kind)->whereIn('source_id', $ids));
                }
            });
            foreach ($q->get(['kind', 'source_id', 'target_id']) as $r) {
                $out[$r->kind][(string) $r->source_id] = (int) $r->target_id;
            }
        }

        return $out;
    }

    /**
     * On a sync, which of these source ids were imported already (so a
     * catch-up pass skips them). Outside a sync: none, and no query.
     *
     * @return array<string,int>
     */
    public function known(string $kind, iterable $srcIds): array
    {
        return $this->sync ? $this->mapGet($kind, is_array($srcIds) ? $srcIds : iterator_to_array($srcIds)) : [];
    }

    /**
     * Like {@see mapGet()}, with the stored content hash.
     *
     * @return array<string,array{0:int,1:?string}>  source_id => [target_id, hash]
     */
    public function mapRows(string $kind, array $srcIds): array
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
                ->get(['source_id', 'target_id', 'hash']);
            foreach ($rows as $r) {
                $out[(string) $r->source_id] = [(int) $r->target_id, $r->hash];
            }
        }

        return $out;
    }
}
