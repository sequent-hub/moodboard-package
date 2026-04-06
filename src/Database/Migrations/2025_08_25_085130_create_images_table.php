<?php

use Illuminate\Database\Migrations\Migration;
use Futurello\MoodBoard\Database\Traits\MigrationLogger;

return new class extends Migration
{
    use MigrationLogger;

    public function up()
    {
        // Intentionally disabled.
        // Images are stored in external object storage and do not use DB table.
        //
        // $this->safeTableOperation('create table', 'images', function () {
        //     Schema::create('images', function (Blueprint $table) {
        //         $table->uuid('id')->primary();
        //         $table->string('name');
        //         $table->string('original_name');
        //         $table->string('path');
        //         $table->string('mime_type');
        //         $table->unsignedBigInteger('size');
        //         $table->unsignedInteger('width');
        //         $table->unsignedInteger('height');
        //         $table->string('hash')->nullable();
        //         $table->timestamps();
        //         $table->index('hash');
        //         $table->index('created_at');
        //     });
        //
        //     $this->logTableInfo('images');
        // });
    }

    public function down()
    {
        // Intentionally disabled.
        //
        // $this->safeTableOperation('drop table', 'images', function () {
        //     Schema::dropIfExists('images');
        // });
    }
};
