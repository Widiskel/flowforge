<?php

declare(strict_types=1);

namespace App\Http\Controllers\Playground;

use App\Http\Controllers\Controller;
use App\Models\PlaygroundItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Public, no-auth playground endpoints used by demo workflows and as a sandbox
 * for user-authored HTTP steps. The handlers fall into five buckets:
 *
 *  - Diagnostics (echo, status, maybe-fail, delay) — stateless utilities.
 *  - Profile lookup (users/{id}, metrics) — synthetic, deterministic.
 *  - Pure-math sandbox (calc/{op}) — drives "compare A vs B" demos.
 *  - Decision sandbox (decisions/{verdict}) — drives auto-approve demos.
 *  - DB-backed CRUD (items/*) — real `playground_items` rows so workflow
 *    authors can exercise mutation end-to-end. Capped at 100 rows via
 *    auto-prune on every list/create call so the table stays small.
 */
class PlaygroundController extends Controller
{
    private const MAX_ROWS = 100;

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
            'result' => $result + 0,
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

    // ------------------------------------------------------------------
    // DB-backed CRUD against `playground_items`. Real Eloquent persistence so
    // workflow authors can demo write paths end-to-end. Self-cleaning.
    // ------------------------------------------------------------------

    public function listItems(Request $request): JsonResponse
    {
        $this->pruneStaleRows();

        $perPage = max(1, min(50, (int) $request->query('per_page', 10)));
        $items = PlaygroundItem::query()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'service' => 'flowforge-playground',
            'data' => $items->items(),
            'meta' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    public function showItem(string $id): JsonResponse
    {
        $item = PlaygroundItem::query()->find($id);

        if (! $item) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'not_found',
                'item_id' => $id,
            ], 404);
        }

        return response()->json([
            'service' => 'flowforge-playground',
            'data' => $item,
        ]);
    }

    public function createItem(Request $request): JsonResponse
    {
        $this->pruneStaleRows();

        $payload = $request->isJson() ? $request->json()->all() : $request->all();

        $validated = Validator::make($payload, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'metadata' => ['nullable', 'array'],
        ])->validate();

        $item = PlaygroundItem::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'quantity' => $validated['quantity'] ?? 0,
            'price_cents' => $validated['price_cents'] ?? 0,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'service' => 'flowforge-playground',
            'data' => $item->fresh(),
        ], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        $item = PlaygroundItem::query()->find($id);

        if (! $item) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'not_found',
                'item_id' => $id,
            ], 404);
        }

        $payload = $request->isJson() ? $request->json()->all() : $request->all();

        $validated = Validator::make($payload, [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $item->fill($validated)->save();

        return response()->json([
            'service' => 'flowforge-playground',
            'data' => $item->fresh(),
        ]);
    }

    public function deleteItem(string $id): JsonResponse
    {
        $item = PlaygroundItem::query()->find($id);

        if (! $item) {
            return response()->json([
                'service' => 'flowforge-playground',
                'error' => 'not_found',
                'item_id' => $id,
            ], 404);
        }

        $item->delete();

        return response()->json([
            'service' => 'flowforge-playground',
            'deleted' => true,
            'id' => $id,
        ]);
    }

    /**
     * Aggregate snapshot over `playground_items`. Returns a real-time
     * inventory summary (total items, low-stock count, total value) the
     * "auto CRUD" demo workflows can react to via CONDITION steps.
     */
    public function inventoryReport(): JsonResponse
    {
        $this->pruneStaleRows();

        $rows = PlaygroundItem::query()->get(['quantity', 'price_cents']);
        $totalItems = $rows->count();
        $totalQuantity = (int) $rows->sum('quantity');
        $totalValueCents = (int) $rows->sum(fn ($r) => $r->quantity * $r->price_cents);
        $lowStock = $rows->where('quantity', '<', 5)->count();

        return response()->json([
            'service' => 'flowforge-playground',
            'inventory' => [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_value_cents' => $totalValueCents,
                'low_stock_items' => $lowStock,
                'reorder_required' => $lowStock > 2,
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Keep the playground table from growing unbounded by trimming the oldest
     * rows whenever we cross MAX_ROWS. Cheap, runs on every CRUD request that
     * adds or lists items. Intentionally not a scheduled job — the data is
     * throwaway demo data.
     */
    private function pruneStaleRows(): void
    {
        $count = PlaygroundItem::query()->count();
        if ($count <= self::MAX_ROWS) {
            return;
        }

        $excess = $count - self::MAX_ROWS;
        $oldestIds = PlaygroundItem::query()
            ->orderBy('created_at')
            ->limit($excess)
            ->pluck('id');

        if ($oldestIds->isNotEmpty()) {
            PlaygroundItem::query()->whereIn('id', $oldestIds)->delete();
        }
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
