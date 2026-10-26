<?php

/*
 * This file is part of ernestdefoe/importer.
 *
 * Web-based forum importer / converter for Flarum 2. Runs in the background,
 * in batches, with a live progress bar in the admin panel.
 */

use ErnestDefoe\Importer\Api\Controller;
use ErnestDefoe\Importer\Auth;
use ErnestDefoe\Importer\Console\ReformatCommand;
use ErnestDefoe\Importer\Console\RepairTextCommand;
use ErnestDefoe\Importer\Console\RunImportCommand;
use ErnestDefoe\Importer\Redirects;
use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    // Members sign in with the password they had on the old board, once:
    // it is then saved the way Flarum saves passwords. See Auth\LegacyPassword.
    (new Extend\Auth())
        ->addPasswordChecker('importer-legacy', Auth\LegacyPasswordChecker::class),

    (new Extend\Event())
        ->listen(\Flarum\User\Event\PasswordChanged::class, Auth\ForgetLegacyPassword::class),

    (new Extend\Console())
        ->command(RunImportCommand::class)
        ->command(RepairTextCommand::class)
        ->command(ReformatCommand::class),

    /*
     * 🚨 Old addresses, 301'd to where the content went.
     *
     * Middleware and not routes: half of these live in a query string —
     * `viewtopic.php?t=123` — which a router cannot express, because to it
     * every one of them is a request for `/index.php`.
     *
     * It only acts on a 404, so Flarum answers everything it knows first and
     * this can never take a URL the site itself serves. Off until the wizard
     * has been finished.
     */
    /*
     * 🚨 `insertBefore` and NOT `add`, and the difference is the whole feature.
     *
     * `add()` appends to the END of the stack — below `ResolveRoute`, which
     * THROWS `RouteNotFoundException` rather than returning a 404. A middleware
     * down there is never called for a missing page at all. Registered that
     * way this redirects nothing, silently, forever.
     *
     * The key is the container binding's name as it appears in
     * `flarum.forum.middleware`, not a class name — `insertBefore` matches the
     * array entry literally.
     */
    (new Extend\Middleware('forum'))
        ->insertBefore('flarum.forum.route_resolver', Redirects\RedirectOldUrls::class),

    // Admin-gated JSON API: test the source connection, start an import, poll progress.
    (new Extend\Routes('api'))
        ->post('/importer/test', 'importer.test', Controller\TestConnectionController::class)
        ->post('/importer/upload', 'importer.upload', Controller\UploadController::class)
        ->post('/importer/start', 'importer.start', Controller\StartImportController::class)
        ->post('/importer/step', 'importer.step', Controller\StepController::class)
        ->post('/importer/reset', 'importer.reset', Controller\ResetController::class)
        ->get('/importer/status', 'importer.status', Controller\StatusController::class)
        ->get('/importer/redirects', 'importer.redirects', Controller\RedirectPreviewController::class)
        ->post('/importer/redirects', 'importer.redirects.save', Controller\RedirectSaveController::class),
];
