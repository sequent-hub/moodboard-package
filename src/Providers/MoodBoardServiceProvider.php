<?php

namespace Futurello\MoodBoard\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\MigrationEnded;
use Futurello\MoodBoard\Http\Middleware\CorsMiddleware;

class MoodBoardServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Регистрируем пакет
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Регистрируем middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('moodboard.cors', CorsMiddleware::class);
        
        // Загружаем маршруты API с middleware
        Route::middleware(['moodboard.cors'])
            ->group(__DIR__.'/../Routes/api.php');
        
        // Загружаем миграции
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        
        // Регистрируем обработчики событий миграций
        $this->registerMigrationEventListeners();
    }

    /**
     * Регистрируем обработчики событий миграций для детального логирования
     */
    protected function registerMigrationEventListeners(): void
    {
        // Начало выполнения всех миграций
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $event) {
            Log::channel('single')->info('🚀 [MOODBOARD PACKAGE] Starting migrations batch', [
                'method' => $event->method,
                'timestamp' => now()->toISOString(),
                'memory_usage' => memory_get_usage(true),
                'package' => 'futurello/moodboard'
            ]);
        });

        // Окончание выполнения всех миграций
        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event) {
            Log::channel('single')->info('✅ [MOODBOARD PACKAGE] Completed migrations batch', [
                'method' => $event->method,
                'timestamp' => now()->toISOString(),
                'memory_usage' => memory_get_usage(true),
                'package' => 'futurello/moodboard'
            ]);
        });

        // Начало выполнения отдельной миграции
        Event::listen(MigrationStarted::class, function (MigrationStarted $event) {
            // Логируем только миграции нашего пакета
            if ($this->isMoodBoardMigration($event->migration)) {
                Log::channel('single')->info('⚡ [MOODBOARD MIGRATION] Starting individual migration', [
                    'migration' => $event->migration->getMigrationName(),
                    'file' => $event->migration->getConnection() ?? 'default',
                    'timestamp' => now()->toISOString(),
                    'memory_usage' => memory_get_usage(true),
                ]);
            }
        });

        // Окончание выполнения отдельной миграции
        Event::listen(MigrationEnded::class, function (MigrationEnded $event) {
            // Логируем только миграции нашего пакета
            if ($this->isMoodBoardMigration($event->migration)) {
                Log::channel('single')->info('✅ [MOODBOARD MIGRATION] Completed individual migration', [
                    'migration' => $event->migration->getMigrationName(),
                    'file' => $event->migration->getConnection() ?? 'default',
                    'timestamp' => now()->toISOString(),
                    'memory_usage' => memory_get_usage(true),
                ]);
            }
        });
    }

    /**
     * Проверяем, является ли миграция частью пакета MoodBoard
     */
    protected function isMoodBoardMigration($migration): bool
    {
        $migrationName = $migration->getMigrationName();
        
        // Проверяем по именам таблиц, которые создает пакет
        return str_contains($migrationName, 'moodboards') || 
               str_contains($migrationName, 'images') || 
               str_contains($migrationName, 'files');
    }
}
