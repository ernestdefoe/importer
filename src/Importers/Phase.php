<?php

namespace ErnestDefoe\Importer\Importers;

/**
 * One phase of an import (categories, members, discussions, posts, …). An
 * importer returns an ordered list of these; the Runner drives them one bounded
 * batch at a time so nothing ever runs long enough to time out.
 */
class Phase
{
    /**
     * @param  string  $key      stable identifier (users, topics, posts…)
     * @param  string  $label    human label for the progress bar
     * @param  \Closure  $count   fn(): int — total source rows (for the % bar)
     * @param  \Closure  $batch   fn(mixed $cursor, int $limit, Ctx $ctx): array
     *                            returns ['cursor'=>mixed, 'processed'=>int, 'done'=>bool, 'summary'=>array<string,int>]
     */
    public function __construct(
        public string $key,
        public string $label,
        public \Closure $count,
        public \Closure $batch,
    ) {}
}
