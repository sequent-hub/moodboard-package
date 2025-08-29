<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateImagesTable extends Migration
{
    public function up()
    {
        Schema::create('images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('hash')->nullable(); // MD5 хеш для дедупликации
            $table->timestamps();

            // Индексы
            $table->index('hash');
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('images');
    }
}
