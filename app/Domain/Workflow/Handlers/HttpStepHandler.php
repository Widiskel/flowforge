<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpStepHandler implements StepHandler
{
    public function handle(array $config, array $context): StepResult
    {
        $url = $config['url'] ?? '';

        // Noop mode: no URL configured, simulate a successful HTTP call.
        if ($url === '') {
            return new StepResult(StepRunStatus::SUCCESS, ['operation' => 'noop', 'note' => 'No URL configured — simulated success.']);
        }

        $method = strtolower($config['method'] ?? 'GET');
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
        } catch (ConnectionException $e) {
            return new StepResult(StepRunStatus::FAILED, [], $e->getMessage());
        }
    }
}
