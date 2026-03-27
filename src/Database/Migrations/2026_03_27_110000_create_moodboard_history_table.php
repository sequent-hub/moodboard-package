<?php

use Futurello\MoodBoard\Database\Traits\MigrationLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use MigrationLogger;

    public function up()
    {
        $this->safeTableOperation('create table', 'moodboard_history', function () {
            Schema::create('moodboard_history', function (Blueprint $table) {
                $table->id();
                $table->string('moodboard_id')->index();
                $table->integer('version');
                $table->json('state_json');
                $table->string('state_hash', 64);
                $table->string('action_type', 64);
                $table->timestamp('created_at');
                $table->string('created_by')->nullable();

                $table->unique(['moodboard_id', 'version']);
                $table->index(['moodboard_id', 'created_at']);
                $table->index(['moodboard_id', 'state_hash']);
            });

            $this->logTableInfo('moodboard_history');
        });
    }

    public function down()
    {
        $this->safeTableOperation('drop table', 'moodboard_history', function () {
            Schema::dropIfExists('moodboard_history');
        });
    }
};
