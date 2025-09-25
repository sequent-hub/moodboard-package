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
        $this->safeTableOperation('create table', 'files', function () {
            Schema::create('files', function (Blueprint $table) {
                $table->id();
                $table->string('name'); // Оригинальное имя файла
                $table->string('filename'); // Имя файла на диске
                $table->string('path'); // Путь к файлу
                $table->string('mime_type'); // MIME тип файла
                $table->bigInteger('size'); // Размер файла в байтах
                $table->string('extension')->nullable(); // Расширение файла
                $table->string('hash')->nullable(); // Хеш файла для дедупликации
                $table->timestamps();

                $table->index('hash');
                $table->index('mime_type');
            });
            
            // Логируем структуру созданной таблицы
            $this->logTableInfo('files');
        });
    }

    public function down()
    {
        $this->safeTableOperation('drop table', 'files', function () {
            Schema::dropIfExists('files');
        });
    }
};
