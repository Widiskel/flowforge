<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;
use Illuminate\Support\Facades\Http;

class HttpStepHandler implements StepHandler
{
    public function handle(array $config, array $context): StepResult
    {
        $method = strtolower($config['method'] ?? 'GET');
        $url = $config['url'] ?? '';
        $timeout = (int) (($config['timeoutMs'] ?? 10000) / 1000);
        $headers = $config['headers'] ?? [];

        try {
            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->{$method}($url);

            if ($response->successful()) {
                return new StepResult(StepRunStatus::SUCCESS, ['status' => $response->status(), 'body' => $response->body()]);
            }

            return new StepResult(StepRunStatus::FAILED, ['status' => $response->status()], 'HTTP request failed with status '.$response->status());
        } catch (\Throwable $e) {
            return new StepResult(StepRunStatus::FAILED, [], $e->getMessage());
        }
    }
}
