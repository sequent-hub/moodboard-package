<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Futurello\MoodBoard\Database\Traits\MigrationLogger;

return new class extends Migration
{
    use MigrationLogger;

    public function up()
    {
        $this->safeTableOperation('create table', 'images', function () {
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
            
            // Логируем структуру созданной таблицы
            $this->logTableInfo('images');
        });
    }

    public function down()
    {
        $this->safeTableOperation('drop table', 'images', function () {
            Schema::dropIfExists('images');
        });
    }
};
