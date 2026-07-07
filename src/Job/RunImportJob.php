<?php

namespace ErnestDefoe\Importer\Job;

use ErnestDefoe\Importer\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background driver — used only when a real queue worker is available. It loops
 * the same {@see Runner::step()} the browser uses, so the import keeps going even
 * if the admin closes the tab. The DB step-lock keeps it and the browser from
 * double-processing; when it can't get the lock it backs off (the browser is
 * driving). With no queue (SyncQueue) this is never dispatched and the browser
 * does everything.
 */
class RunImportJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        for ($i = 0; $i < 5_000_000; $i++) { // generous cap: 200 rows/step → up to 1e9 rows
            $st = Runner::step($this->runId);
            if (! empty($st['done']) || ! empty($st['failed']) || empty($st['running'])) {
                break;
            }
            if (! empty($st['skipped'])) {
                sleep(1); // the browser is stepping — ease off
            }
        }
    }
}
