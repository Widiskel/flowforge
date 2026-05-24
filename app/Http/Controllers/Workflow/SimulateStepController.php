<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Domain\Workflow\Handlers\ConditionStepHandler;
use App\Domain\Workflow\Handlers\DelayStepHandler;
use App\Domain\Workflow\Handlers\HttpStepHandler;
use App\Domain\Workflow\Handlers\LogStepHandler;
use App\Domain\Workflow\Handlers\ScriptStepHandler;
use App\Domain\Workflow\Handlers\StepHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single-step sandbox used by the Node Inspector's Input/Output tabs in the
 * builder. The user provides a step's type + config + an optional upstream
 * context, and we run it through the same handler stack the executor uses.
 *
 * Read-only by design: nothing here touches the DB. Everything is bounded by
 * existing handler-level guards (HTTP timeout, DELAY cap, SCRIPT allowlist).
 */
class SimulateStepController extends Controller
{
    /** Lookup table mirrors WorkflowExecutor::__construct. */
    private const HANDLERS = [
        'HTTP' => HttpStepHandler::class,
        'DELAY' => DelayStepHandler::class,
        'CONDITION' => ConditionStepHandler::class,
        'SCRIPT' => ScriptStepHandler::class,
        'LOG' => LogStepHandler::class,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:HTTP,DELAY,CONDITION,SCRIPT,LOG'],
            'config' => ['nullable', 'array'],
            'input' => ['nullable', 'array'],
        ]);

        $type = strtoupper((string) $validated['type']);
        $config = (array) ($validated['config'] ?? []);
        $context = (array) ($validated['input'] ?? []);

        $handlerClass = self::HANDLERS[$type] ?? null;
        if (! $handlerClass) {
            return response()->json([
                'message' => sprintf('Unknown step type: %s', $type),
            ], 422);
        }

        /** @var StepHandler $handler */
        $handler = app($handlerClass);

        $startedAt = microtime(true);
        $result = $handler->handle($config, $context);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return response()->json([
            'data' => [
                'status' => $result->status->value,
                'output' => $result->output,
                'error' => $result->error,
                'duration_ms' => $durationMs,
            ],
        ]);
    }
}
