<?php

/*
 * This file is part of ernestdefoe/importer.
 *
 * Persistent state for step-based, resumable imports — so an import survives
 * request timeouts and a closed browser tab, and needs no queue worker (works
 * on shared hosting).
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per import run: which source, its config, and the live state
        // (phase, cursor, totals, running summary, per-discussion carry).
        $schema->create('importer_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source', 40);
            $table->mediumText('config')->nullable(); // JSON source-DB config; wiped when the run finishes
            $table->mediumText('state');               // JSON state machine
            $table->string('status', 20)->default('running'); // running | done | failed
            $table->text('error')->nullable();
            $table->timestamp('locked_at')->nullable(); // step mutex, so a queue worker + the browser never double-process
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // source id → Flarum id maps, kept in the DB so they survive across the
        // many short requests a single import is spread over.
        $schema->create('importer_map', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('run_id')->unsigned();
            $table->string('kind', 20);      // user | tag | topic
            $table->string('source_id', 64);
            $table->unsignedInteger('target_id');
            $table->index(['run_id', 'kind', 'source_id'], 'importer_map_lookup');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('importer_map');
        $schema->dropIfExists('importer_runs');
    },
];
