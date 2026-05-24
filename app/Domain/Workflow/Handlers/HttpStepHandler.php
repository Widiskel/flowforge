<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpStepHandler implements StepHandler
{
    /**
     * Schemes the HTTP step is allowed to call. Anything else is rejected
     * before the request leaves the process — `file://`, `gopher://`, etc.
     * are common SSRF vectors.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function handle(array $config, array $context): StepResult
    {
        $url = $config['url'] ?? '';

        // Noop mode: no URL configured, simulate a successful HTTP call.
        if ($url === '') {
            return new StepResult(StepRunStatus::SUCCESS, ['operation' => 'noop', 'note' => 'No URL configured — simulated success.']);
        }

        if (! is_string($url) || ($guardError = $this->rejectUnsafeUrl($url)) !== null) {
            return new StepResult(StepRunStatus::FAILED, ['url' => $url], $guardError ?? 'Invalid URL.');
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

    /**
     * Validate the URL before letting the HTTP client touch it. Returns null
     * when the URL is allowed; otherwise an explanatory error string the run
     * record will surface as the step error.
     *
     * Loopback / private-IP guarding can be relaxed via the
     * `flowforge.http_step_allow_private_network` config (default true in
     * dev so the playground works against `127.0.0.1`, set to false in
     * production to enforce SSRF blocking).
     */
    private function rejectUnsafeUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! is_array($parts)) {
            return 'URL could not be parsed.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return sprintf('URL scheme "%s" is not allowed; allowed: %s.', $scheme, implode(', ', self::ALLOWED_SCHEMES));
        }

        $host = $parts['host'] ?? '';
        if (! is_string($host) || $host === '') {
            return 'URL host is missing.';
        }

        if (config('flowforge.http_step_allow_private_network', true)) {
            return null;
        }

        // Resolve hostname → IP and reject loopback / link-local / private
        // ranges that an attacker might use to pivot inside the cluster.
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (! $ip || ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP))) {
            return sprintf('Host "%s" could not be resolved to an IP.', $host);
        }

        if (! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        )) {
            return sprintf('Host "%s" resolves to a private/loopback address (%s); blocked.', $host, $ip);
        }

        return null;
    }
}
