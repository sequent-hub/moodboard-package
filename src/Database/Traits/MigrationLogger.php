<?php

namespace Futurello\MoodBoard\Database\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Exception;

trait MigrationLogger
{
    /**
     * Логирование начала выполнения миграции
     */
    protected function logMigrationStart(string $action, string $tableName): void
    {
        $migrationName = $this->getMigrationName();
        
        Log::channel('single')->info("🚀 [MOODBOARD MIGRATION] Starting {$action}", [
            'migration' => $migrationName,
            'table' => $tableName,
            'action' => $action,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
        ]);
    }

    /**
     * Логирование успешного завершения миграции
     */
    protected function logMigrationSuccess(string $action, string $tableName): void
    {
        $migrationName = $this->getMigrationName();
        
        Log::channel('single')->info("✅ [MOODBOARD MIGRATION] Successfully completed {$action}", [
            'migration' => $migrationName,
            'table' => $tableName,
            'action' => $action,
            'timestamp' => now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
            'table_exists' => Schema::hasTable($tableName),
        ]);
    }

    /**
     * Логирование ошибки выполнения миграции
     */
    protected function logMigrationError(string $action, string $tableName, Exception $exception): void
    {
        $migrationName = $this->getMigrationName();
        
        Log::channel('single')->error("❌ [MOODBOARD MIGRATION] Failed {$action}", [
            'migration' => $migrationName,
            'table' => $tableName,
            'action' => $action,
            'timestamp' => now()->toISOString(),
            'error' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'memory_usage' => memory_get_usage(true),
            'table_exists' => Schema::hasTable($tableName),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Безопасное выполнение операции с таблицей
     */
    protected function safeTableOperation(string $action, string $tableName, callable $operation): void
    {
        try {
            $this->logMigrationStart($action, $tableName);
            
            // Выполняем операцию
            $operation();
            
            $this->logMigrationSuccess($action, $tableName);
            
        } catch (Exception $e) {
            $this->logMigrationError($action, $tableName, $e);
            throw $e; // Пробрасываем исключение дальше
        }
    }

    /**
     * Получение имени миграции из класса
     */
    protected function getMigrationName(): string
    {
        $className = get_class($this);
        
        // Для анонимных классов попытаемся извлечь имя из файла
        if (str_contains($className, 'class@anonymous')) {
            $reflection = new \ReflectionClass($this);
            $filename = $reflection->getFileName();
            
            if ($filename) {
                return basename($filename, '.php');
            }
        }
        
        return $className;
    }

    /**
     * Логирование информации о таблице
     */
    protected function logTableInfo(string $tableName): void
    {
        if (Schema::hasTable($tableName)) {
            $columns = Schema::getColumnListing($tableName);
            
            Log::channel('single')->info("📊 [MOODBOARD MIGRATION] Table structure", [
                'table' => $tableName,
                'columns' => $columns,
                'column_count' => count($columns),
                'timestamp' => now()->toISOString(),
            ]);
        }
    }
}
