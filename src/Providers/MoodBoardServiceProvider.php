<?php

namespace Futurello\MoodBoard\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
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
    }
}
