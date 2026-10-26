<?php

/*
 * This file is part of ernestdefoe/importer.
 *
 * Old boards' password hashes that Flarum cannot check itself (phpBB 3.0's
 * phpass, vBulletin's md5-and-salt, MyBB, SMF, XenForo 1, Invision,
 * Discourse, NodeBB…), kept apart from `users.password`, which holds a random
 * hash until the member's first sign-in. Then the old hash is checked, the
 * real password saved the way Flarum saves one, and the row deleted.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->create('importer_passwords', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->string('scheme', 32);
            $table->string('hash', 255);
            $table->string('salt', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('importer_passwords');
    },
];
