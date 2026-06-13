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
use Futurello\MoodBoard\Console\Commands\MigrateMoodboardsToHistory;
use Futurello\MoodBoard\Services\Ai\DeepSeekProvider;
use Futurello\MoodBoard\Services\Ai\Hunyuan3dProvider;
use Futurello\MoodBoard\Services\Ai\OpenAiImageProvider;
use Futurello\MoodBoard\Services\Ai\Support\ProviderRegistry;
use Futurello\MoodBoard\Services\Ai\YandexArtProvider;
use Futurello\MoodBoard\Services\Ai\YandexProvider;
use Illuminate\Http\Client\Factory as HttpFactory;

class MoodBoardServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateMoodboardsToHistory::class,
            ]);
        }

        $this->mergeConfigFrom(__DIR__.'/../../config/moodboard-ai.php', 'moodboard-ai');

        $this->registerAiBindings();
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
        // Legacy routes file is intentionally disabled (kept for reference).
        // Route::middleware(['moodboard.cors'])
        //     ->group(__DIR__.'/../Routes/api.php');
        Route::middleware(['moodboard.cors'])
            ->group(__DIR__.'/../Routes/api_v2.php');
        
        // Загружаем миграции
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Публикация AI-конфига в config/ родительского приложения.
        // Пользоваться: php artisan vendor:publish --tag=moodboard-ai-config
        $this->publishes([
            __DIR__.'/../../config/moodboard-ai.php' => config_path('moodboard-ai.php'),
        ], 'moodboard-ai-config');

        // Регистрируем обработчики событий миграций
        $this->registerMigrationEventListeners();
    }

    /**
     * Биндинги AI-провайдеров и реестра.
     *
     * Каждый провайдер инстанциируется один раз на запрос (singleton),
     * читает свою секцию из config('moodboard-ai.providers.*'). Если ключи
     * не заданы — провайдер возвращает isEnabled()=false и контроллер
     * вернёт клиенту 503 для именно этого провайдера.
     */
    private function registerAiBindings(): void
    {
        $this->app->singleton(DeepSeekProvider::class, function ($app) {
            return new DeepSeekProvider(
                $app->make(HttpFactory::class),
                (array) config('moodboard-ai.providers.deepseek'),
                (array) config('moodboard-ai.http'),
            );
        });

        $this->app->singleton(YandexProvider::class, function ($app) {
            return new YandexProvider(
                $app->make(HttpFactory::class),
                (array) config('moodboard-ai.providers.yandex'),
                (array) config('moodboard-ai.http'),
            );
        });

        $this->app->singleton(YandexArtProvider::class, function ($app) {
            return new YandexArtProvider(
                $app->make(HttpFactory::class),
                (array) config('moodboard-ai.providers.yandex_art'),
                (array) config('moodboard-ai.http'),
            );
        });

        $this->app->singleton(OpenAiImageProvider::class, function ($app) {
            return new OpenAiImageProvider(
                $app->make(HttpFactory::class),
                (array) config('moodboard-ai.providers.openai_image'),
                (array) config('moodboard-ai.http'),
            );
        });

        $this->app->singleton(Hunyuan3dProvider::class, function ($app) {
            return new Hunyuan3dProvider(
                $app->make(HttpFactory::class),
                (array) config('moodboard-ai.providers.hunyuan_3d'),
                (array) config('moodboard-ai.http'),
            );
        });

        $this->app->singleton(ProviderRegistry::class, function ($app) {
            return new ProviderRegistry([
                'yandex' => [
                    'label'           => 'YandexGPT',
                    'provider'        => $app->make(YandexProvider::class),
                    'supportedRatios' => null,
                ],
                'yandex-art' => [
                    'label'           => 'YandexART',
                    'provider'        => $app->make(YandexArtProvider::class),
                    'supportedRatios' => null,
                ],
                'deepseek' => [
                    'label'           => 'DeepSeek',
                    'provider'        => $app->make(DeepSeekProvider::class),
                    'supportedRatios' => null,
                ],
                'openai-image' => [
                    'label'           => 'OpenAI Images',
                    'provider'        => $app->make(OpenAiImageProvider::class),
                    'supportedRatios' => ['1:1', '3:2', '2:3'],
                ],
                'hunyuan-3d' => [
                    'label' => 'Hunyuan 3D',
                    'provider' => $app->make(Hunyuan3dProvider::class),
                ],
            ]);
        });
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
                    'migration' => (new \ReflectionClass($event->migration))->getFileName(),
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
                    'migration' => (new \ReflectionClass($event->migration))->getFileName(),
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
        try {
            // Получаем имя класса миграции
            $className = get_class($migration);
            
            // Для анонимных классов получаем имя файла через рефлексию
            if (str_contains($className, 'class@anonymous')) {
                $reflection = new \ReflectionClass($migration);
                $filename = $reflection->getFileName();
                
                if ($filename) {
                    $migrationName = basename($filename, '.php');
                    
                    // Проверяем по именам таблиц, которые создает пакет
                    return str_contains($migrationName, 'moodboards') || 
                           str_contains($migrationName, 'images') || 
                           str_contains($migrationName, 'files');
                }
            }
            
            // Для именованных классов используем имя класса
            return str_contains($className, 'MoodBoard') ||
                   str_contains($className, 'CreateMoodboardsTable') ||
                   str_contains($className, 'CreateImagesTable') ||
                   str_contains($className, 'CreateFilesTable');
                   
        } catch (\Exception $e) {
            // В случае ошибки логируем и возвращаем false
            Log::channel('single')->warning('⚠️ [MOODBOARD PACKAGE] Could not determine migration ownership', [
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString(),
            ]);
            
            return false;
        }
    }
}
