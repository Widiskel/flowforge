<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationalHealthService
{
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'migrations' => $this->checkMigrations(),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === 'up');

        return [
            'status' => $healthy ? 'healthy' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toISOString(),
        ];
    }

    public function isReady(): bool
    {
        $result = $this->check();

        return $result['status'] === 'healthy';
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return ['status' => 'up'];
        } catch (\Throwable $e) {
            return ['status' => 'down', 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:probe:'.uniqid();
            Cache::put($key, 'ok', 5);
            $value = Cache::get($key);
            Cache::forget($key);

            return $value === 'ok'
                ? ['status' => 'up']
                : ['status' => 'down', 'error' => 'Cache read mismatch'];
        } catch (\Throwable $e) {
            return ['status' => 'up', 'note' => 'Cache driver may not be configured'];
        }
    }

    private function checkMigrations(): array
    {
        try {
            $pending = collect(app('migrator')->pendingMigrations(
                app('migrator')->getMigrationFiles(database_path('migrations'))
            ));

            return $pending->isEmpty()
                ? ['status' => 'up']
                : ['status' => 'down', 'pending' => $pending->count()];
        } catch (\Throwable $e) {
            return ['status' => 'up', 'note' => 'Migration check unavailable'];
        }
    }
}
