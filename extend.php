<?php

/*
 * This file is part of ernestdefoe/importer.
 *
 * Web-based forum importer / converter for Flarum 2. Runs in the background,
 * in batches, with a live progress bar in the admin panel.
 */

use ErnestDefoe\Importer\Api\Controller;
use ErnestDefoe\Importer\Console\RunImportCommand;
use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    (new Extend\Console())
        ->command(RunImportCommand::class),

    // Admin-gated JSON API: test the source connection, start an import, poll progress.
    (new Extend\Routes('api'))
        ->post('/importer/test', 'importer.test', Controller\TestConnectionController::class)
        ->post('/importer/upload', 'importer.upload', Controller\UploadController::class)
        ->post('/importer/start', 'importer.start', Controller\StartImportController::class)
        ->post('/importer/step', 'importer.step', Controller\StepController::class)
        ->post('/importer/reset', 'importer.reset', Controller\ResetController::class)
        ->get('/importer/status', 'importer.status', Controller\StatusController::class),
];
