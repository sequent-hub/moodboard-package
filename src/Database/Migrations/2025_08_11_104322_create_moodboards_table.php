<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('moodboards', function (Blueprint $table) {
            $table->id();
            $table->string('board_id')->unique()->index(); // Публичный ID доски
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('data'); // Основные данные доски (объекты, настройки)
            $table->json('settings')->nullable(); // Настройки доски (фон, сетка и т.д.)
            $table->integer('version')->default(1); // Версия для конфликтов
            $table->timestamp('last_saved_at');
            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index(['board_id', 'updated_at']);
            $table->index('last_saved_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('moodboards');
    }
};
