<?php

namespace Futurello\MoodBoard\Tests;

use Futurello\MoodBoard\Providers\MoodBoardServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected string $testConnection;
    protected array $testingEnv = [];

    protected function getPackageProviders($app): array
    {
        return [
            MoodBoardServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->testingEnv = $this->readTestingEnvironment();
        $this->testConnection = (string) $this->getTestingEnv('DB_CONNECTION', 'mysql');

        $app['config']->set('database.default', $this->testConnection);
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => $this->getTestingEnv('DB_HOST', '127.0.0.1'),
            'port' => $this->getTestingEnv('DB_PORT', '3306'),
            'database' => $this->getTestingEnv('DB_DATABASE', 'forge'),
            'username' => $this->getTestingEnv('DB_USERNAME', 'forge'),
            'password' => $this->getTestingEnv('DB_PASSWORD', ''),
            'unix_socket' => $this->getTestingEnv('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ]);

        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/local'),
            'throw' => false,
        ]);

        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/public'),
            'url' => ((string) $this->getTestingEnv('APP_URL', 'http://localhost')) . '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);

        // In tests we map s3 disk to a local folder.
        $app['config']->set('filesystems.disks.s3', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/s3'),
            'url' => ((string) $this->getTestingEnv('APP_URL', 'http://localhost')) . '/storage/s3',
            'visibility' => 'public',
            'throw' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--database' => $this->testConnection]);
    }

    private function readTestingEnvironment(): array
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env.testing';

        if (!file_exists($path)) {
            return [];
        }

        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $result[trim($key)] = trim($value);
        }

        return $result;
    }

    private function getTestingEnv(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->testingEnv)) {
            return $this->testingEnv[$key];
        }

        return $default;
    }
}
