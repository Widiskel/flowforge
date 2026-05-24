<?php

declare(strict_types=1);

namespace App\Http\Controllers\Playground;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public, no-auth playground endpoints used by demo workflows and as a sandbox
 * for user-authored HTTP steps. Every endpoint is deterministic-ish and safe;
 * none of them touch business data.
 *
 * The handlers fall into four buckets:
 *  - Diagnostics (echo, status, maybe-fail, delay)
 *  - Profile lookup (users/{id}, metrics)
 *  - Pure math sandbox (calc/sum, calc/diff, calc/multiply) — drives the
 *    "compare process A vs process B" demo workflow
 *  - Decision sandbox (decisions/approve, decisions/reject) — drives the
 *    auto-approve workflows
 *  - Mock CRUD (items/* + inventory) — drives the CRUD demo workflows
 */
class PlaygroundController extends Controller
{
    public function echo(Request $request): JsonResponse
    {
        return response()->json([
            'service' => 'flowforge-playground',
            'echo' => [
                'method' => $request->method(),
                'query' => $request->query(),
                'headers' => $this->safeHeaders($request),
                'body' => $request->isJson() ? $request->json()->all() : $request->all(),
            ],
            'received_at' => now()->toIso8601String(),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $code = (int) $request->query('code', 200);
        if ($code < 100 || $code > 599) {
            $code = 200;
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'requested_status' => $code,
            'message' => $this->describeStatus($code),
        ], $code);
    }

    public function maybeFail(Request $request): JsonResponse
    {
        $rate = (float) $request->query('fail_rate', 0.3);
        $rate = max(0.0, min(1.0, $rate));
        $roll = mt_rand() / mt_getrandmax();
        $shouldFail = $roll < $rate;

        $payload = [
            'service' => 'flowforge-playground',
            'roll' => round($roll, 4),
            'fail_rate' => $rate,
            'outcome' => $shouldFail ? 'fail' : 'ok',
        ];

        return response()->json($payload, $shouldFail ? 503 : 200);
    }

    public function delay(Request $request): JsonResponse
    {
        $ms = (int) $request->query('ms', 250);
        $ms = max(0, min(5000, $ms));
        usleep($ms * 1000);

        return response()->json([
            'service' => 'flowforge-playground',
            'delayed_ms' => $ms,
        ]);
    }

    public function userById(Request $request, int $id): JsonResponse
    {
        $names = ['Audrey', 'Mateus', 'Khadija', 'Soren', 'Priya', 'Lukas', 'Naoko', 'Diego'];
        $domains = ['example.com', 'demo.test', 'forge.dev'];
        $name = $names[$id % count($names)];
        $domain = $domains[$id % count($domains)];

        return response()->json([
            'service' => 'flowforge-playground',
            'user' => [
                'id' => $id,
                'name' => $name.' #'.$id,
                'email' => strtolower($name).$id.'@'.$domain,
                'verified' => $id % 3 !== 0,
                'plan' => $id % 4 === 0 ? 'enterprise' : ($id % 2 === 0 ? 'pro' : 'starter'),
            ],
        ]);
    }

    public function notify(Request $request): JsonResponse
    {
        $channel = strtolower((string) $request->input('channel', 'log'));
        $message = (string) $request->input('message', 'no message');

        $allowed = ['log', 'email', 'slack', 'webhook'];
        if (! in_array($channel, $allowed, true)) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'unsupported_channel',
                'allowed' => $allowed,
            ], 422);
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'channel' => $channel,
            'queued_id' => (string) Str::uuid(),
            'message_preview' => Str::limit($message, 120),
            'queued_at' => now()->toIso8601String(),
        ]);
    }

    public function metrics(): JsonResponse
    {
        $now = now()->toIso8601String();

        return response()->json([
            'service' => 'flowforge-playground',
            'as_of' => $now,
            'cpu_usage_pct' => round(mt_rand(1500, 7800) / 100, 2),
            'memory_usage_pct' => round(mt_rand(2000, 8500) / 100, 2),
            'open_orders' => mt_rand(120, 2400),
            'queue_depth' => mt_rand(0, 38),
            'health' => mt_rand() % 7 === 0 ? 'degraded' : 'ok',
        ]);
    }

    /**
     * Pure-math sandbox. Accepts query params or JSON body with `a` and `b`,
     * returns the result keyed under `result` so a downstream CONDITION step
     * can branch on it via `data_get(context, '<step>.json.result')`.
     */
    public function calc(Request $request, string $op): JsonResponse
    {
        $a = $this->numeric($request, 'a');
        $b = $this->numeric($request, 'b');

        if ($a === null || $b === null) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'invalid_operands',
                'message' => 'Both `a` and `b` must be numeric.',
            ], 422);
        }

        $result = match (strtolower($op)) {
            'sum', 'add' => $a + $b,
            'diff', 'subtract' => $a - $b,
            'multiply', 'product' => $a * $b,
            'divide' => $b !== 0.0 ? $a / $b : null,
            default => null,
        };

        if ($result === null) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'unsupported_op_or_division_by_zero',
            ], 422);
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'op' => strtolower($op),
            'a' => $a,
            'b' => $b,
            'result' => $result + 0, // ensure numeric (not string) in JSON
        ]);
    }

    /**
     * Decision sandbox. POST /decisions/approve or /decisions/reject.
     * Echoes a ticket-style decision payload so a workflow that branches on
     * "is process A bigger than process B" can follow up with an explicit
     * approve/reject call.
     */
    public function decision(Request $request, string $verdict): JsonResponse
    {
        $verdict = strtolower($verdict);
        if (! in_array($verdict, ['approve', 'reject'], true)) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'unsupported_verdict',
                'allowed' => ['approve', 'reject'],
            ], 422);
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'decision' => [
                'id' => (string) Str::uuid(),
                'verdict' => $verdict,
                'subject' => $request->input('subject', 'unspecified'),
                'reason' => $request->input('reason', $verdict === 'approve' ? 'auto-approved' : 'auto-rejected'),
                'amount' => $request->input('amount'),
                'decided_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Mock CRUD: items collection. POST creates a synthetic item, GET returns
     * a deterministic sample list. Used by the "auto CRUD" demo workflow.
     */
    public function listItems(): JsonResponse
    {
        $items = [];
        foreach (range(1, 5) as $i) {
            $items[] = [
                'id' => $i,
                'sku' => 'SKU-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'name' => 'Demo widget #'.$i,
                'stock' => mt_rand(0, 80),
                'unit_price' => round(mt_rand(500, 2500) / 100, 2),
                'updated_at' => now()->subMinutes(mt_rand(0, 600))->toIso8601String(),
            ];
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'items' => $items,
            'count' => count($items),
        ]);
    }

    public function createItem(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $stock = (int) $request->input('stock', 0);
        $price = (float) $request->input('unit_price', 0);

        if ($name === '' || $stock < 0 || $price < 0) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'invalid_payload',
                'message' => '`name` is required, `stock` and `unit_price` must be non-negative.',
            ], 422);
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'item' => [
                'id' => mt_rand(1000, 9999),
                'sku' => 'SKU-'.strtoupper(Str::random(6)),
                'name' => $name,
                'stock' => $stock,
                'unit_price' => $price,
                'created_at' => now()->toIso8601String(),
            ],
        ], 201);
    }

    public function showItem(int $id): JsonResponse
    {
        return response()->json([
            'service' => 'flowforge-playground',
            'item' => [
                'id' => $id,
                'sku' => 'SKU-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'name' => 'Demo widget #'.$id,
                'stock' => max(0, ($id * 7) % 90),
                'unit_price' => round(($id * 1.37) + 4.99, 2),
                'fetched_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $patch = array_intersect_key(
            $request->all(),
            array_flip(['name', 'stock', 'unit_price']),
        );

        return response()->json([
            'service' => 'flowforge-playground',
            'item' => array_merge([
                'id' => $id,
                'sku' => 'SKU-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'updated_at' => now()->toIso8601String(),
            ], $patch),
            'fields_changed' => array_keys($patch),
        ]);
    }

    public function deleteItem(int $id): JsonResponse
    {
        return response()->json([
            'service' => 'flowforge-playground',
            'deleted_id' => $id,
            'deleted_at' => now()->toIso8601String(),
        ]);
    }

    public function inventoryReport(): JsonResponse
    {
        $low = mt_rand(0, 6);
        $total = mt_rand(40, 220);

        return response()->json([
            'service' => 'flowforge-playground',
            'inventory' => [
                'total_items' => $total,
                'low_stock_items' => $low,
                'reorder_required' => $low > 2,
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Numeric param resolver — looks at both query string and JSON body so
     * playground calc endpoints can be called either way.
     */
    private function numeric(Request $request, string $key): ?float
    {
        $candidates = [
            $request->query($key),
            $request->input($key),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function safeHeaders(Request $request): array
    {
        $blocked = ['authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-csrf-token'];
        $out = [];
        foreach ($request->headers->all() as $key => $values) {
            if (in_array(strtolower($key), $blocked, true)) {
                $out[$key] = '[redacted]';

                continue;
            }
            $out[$key] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return $out;
    }

    private function describeStatus(int $code): string
    {
        return match (true) {
            $code >= 200 && $code < 300 => 'success',
            $code >= 300 && $code < 400 => 'redirect',
            $code === 401 => 'unauthorized',
            $code === 403 => 'forbidden',
            $code === 404 => 'not_found',
            $code === 422 => 'validation_failed',
            $code >= 400 && $code < 500 => 'client_error',
            $code === 503 => 'service_unavailable',
            $code >= 500 => 'server_error',
            default => 'unknown',
        };
    }
}
