<?php

/*
 * This file is part of ernestdefoe/importer.
 *
 * The old board's attachments once they are copied here: one row per source
 * attachment (or per image the posts linked on the old board's own host), so
 * a quote, a resumed step, a sync or an edit reuses the copy instead of
 * storing the file a second time.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('importer_files', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('run_id');
            $table->string('source_id', 80);
            $table->string('url', 2000);
            $table->string('name', 255);
            $table->boolean('is_image')->default(false);
            // The fof_upload_files row, when FoF Upload is installed.
            $table->unsignedInteger('upload_id')->nullable();

            $table->unique(['run_id', 'source_id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('importer_files');
    },
];
