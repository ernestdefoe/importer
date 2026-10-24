<?php

/*
 * This file is part of ernestdefoe/importer.
 *
 * Incremental sync: a sync run points at the import it continues, and a
 * post's map row remembers the text it was written with, so an edit made on
 * the old board is only copied over a post nobody has edited here.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->table('importer_runs', function (Blueprint $table) {
            $table->unsignedInteger('base_run_id')->nullable()->index();
        });
        $schema->table('importer_map', function (Blueprint $table) {
            $table->string('hash', 40)->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('importer_map', function (Blueprint $table) {
            $table->dropColumn('hash');
        });
        $schema->table('importer_runs', function (Blueprint $table) {
            $table->dropIndex(['base_run_id']);
            $table->dropColumn('base_run_id');
        });
    },
];
