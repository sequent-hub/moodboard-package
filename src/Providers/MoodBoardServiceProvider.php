<?php

namespace Futurello\MoodBoard\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

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
        // Загружаем маршруты API
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        
        // Загружаем миграции
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
