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
        $body = $config['body'] ?? null;

        try {
            $request = Http::timeout($timeout)->withHeaders($headers);

            // Methods that carry a body get the JSON body forwarded — required
            // by the playground POST endpoints (notify, calc, items, decisions).
            $bodyMethods = ['post', 'put', 'patch', 'delete'];
            if (in_array($method, $bodyMethods, true) && $body !== null) {
                $request = $request->asJson();
                $response = $request->{$method}($url, is_array($body) ? $body : [$body]);
            } else {
                $response = $request->{$method}($url);
            }

            if ($response->successful()) {
                $body = $response->body();
                $payload = ['status' => $response->status(), 'body' => $body];

                // Expose the parsed JSON when the upstream returns JSON. This
                // lets downstream CONDITION/SCRIPT steps use `data_get` paths
                // like `<step>.json.result` instead of trying to parse strings
                // — required for the seeded sum-vs-diff demo and any workflow
                // that branches on numeric upstream output.
                $contentType = strtolower((string) $response->header('Content-Type'));
                if (str_contains($contentType, 'json') || str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[')) {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded)) {
                        $payload['json'] = $decoded;
                    }
                }

                return new StepResult(StepRunStatus::SUCCESS, $payload);
            }

            return new StepResult(StepRunStatus::FAILED, ['status' => $response->status()], 'HTTP request failed with status '.$response->status());
        } catch (ConnectionException $e) {
            return new StepResult(StepRunStatus::FAILED, [], $e->getMessage());
        }
    }
}
