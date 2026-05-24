<?php

declare(strict_types=1);

use App\Http\Controllers\Ai\AnalyzeFailureController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GraphQLController;
use App\Http\Controllers\Monitoring\HealthMetricsController;
use App\Http\Controllers\Monitoring\OperationalProbeController;
use App\Http\Controllers\Playground\PlaygroundController;
use App\Http\Controllers\Workflow\ExecutionLogController;
use App\Http\Controllers\Workflow\RunEventStreamController;
use App\Http\Controllers\Workflow\SimulateStepController;
use App\Http\Controllers\Workflow\WebhookController;
use App\Http\Controllers\Workflow\WorkflowController;
use App\Http\Controllers\Workflow\WorkflowRunController;
use App\Http\Controllers\Workflow\WorkflowTriggerController;
use App\Http\Controllers\Workflow\WorkflowVersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:api');
});

Route::middleware(['jwt.query', 'auth:api', 'tenant', 'throttle:api'])->group(function (): void {
    Route::get('/workflows', [WorkflowController::class, 'index']);
    Route::post('/workflows', [WorkflowController::class, 'store']);
    Route::get('/workflows/{workflow}', [WorkflowController::class, 'show']);
    Route::put('/workflows/{workflow}', [WorkflowController::class, 'update']);
    Route::delete('/workflows/{workflow}', [WorkflowController::class, 'destroy']);

    Route::get('/workflows/{workflow}/versions', [WorkflowVersionController::class, 'index']);
    Route::post('/workflows/{workflow}/rollback/{version}', [WorkflowVersionController::class, 'rollback']);
    Route::get('/workflows/{workflow}/triggers', [WorkflowTriggerController::class, 'index']);
    Route::post('/workflows/{workflow}/triggers', [WorkflowTriggerController::class, 'store']);
    Route::delete('/workflows/{workflow}/triggers/{trigger}', [WorkflowTriggerController::class, 'destroy']);

    Route::post('/workflows/{workflow}/trigger', [WorkflowRunController::class, 'trigger']);

    // Single-step sandbox used by the Node Inspector simulate buttons. Same
    // tenant + JWT guard as everything else in this group; nothing persists.
    Route::post('/workflows/simulate-step', SimulateStepController::class);

    Route::get('/workflow-runs', [WorkflowRunController::class, 'index']);
    Route::get('/workflow-runs/{run}', [WorkflowRunController::class, 'show']);
    Route::get('/workflow-runs/{run}/events', [RunEventStreamController::class, '__invoke'])->withoutMiddleware('throttle:api')->middleware('throttle:sse');
    Route::get('/workflow-runs/{run}/logs', [ExecutionLogController::class, '__invoke']);
    Route::get('/health/metrics', [HealthMetricsController::class, '__invoke'])->withoutMiddleware('throttle:api')->middleware('throttle:metrics');
    Route::post('/workflow-runs/{run}/analyze-failure', [AnalyzeFailureController::class, '__invoke'])->withoutMiddleware('throttle:api')->middleware('throttle:ai-analyze');

    // GraphQL bonus surface — same JWT/tenant guard as REST.
    Route::post('/graphql', GraphQLController::class);
});

Route::get('/healthz/ready', [OperationalProbeController::class, 'ready']);
Route::get('/healthz/startup', [OperationalProbeController::class, 'startup']);
Route::get('/actuator/health', [OperationalProbeController::class, 'actuator']);

// Public playground used by demo workflows and as a sandbox for user-authored
// HTTP steps. Throttled and never returns business data.
Route::middleware('throttle:playground')->prefix('playground')->group(function (): void {
    Route::match(['GET', 'POST'], '/echo', [PlaygroundController::class, 'echo']);
    Route::get('/status', [PlaygroundController::class, 'status']);
    Route::get('/maybe-fail', [PlaygroundController::class, 'maybeFail']);
    Route::get('/delay', [PlaygroundController::class, 'delay']);
    Route::get('/users/{id}', [PlaygroundController::class, 'userById']);
    Route::post('/notify', [PlaygroundController::class, 'notify']);
    Route::get('/metrics', [PlaygroundController::class, 'metrics']);

    // Pure-math sandbox.
    Route::match(['GET', 'POST'], '/calc/{op}', [PlaygroundController::class, 'calc']);

    // Decision sandbox.
    Route::post('/decisions/{verdict}', [PlaygroundController::class, 'decision']);

    // DB-backed CRUD against `playground_items`. Real Eloquent persistence
    // so demo workflows can exercise mutation end-to-end. Self-cleaning
    // (capped at 100 rows total via auto-prune).
    Route::get('/items', [PlaygroundController::class, 'listItems']);
    Route::post('/items', [PlaygroundController::class, 'createItem']);
    Route::get('/items/{id}', [PlaygroundController::class, 'showItem']);
    Route::match(['PUT', 'PATCH'], '/items/{id}', [PlaygroundController::class, 'updateItem']);
    Route::delete('/items/{id}', [PlaygroundController::class, 'deleteItem']);
    Route::get('/inventory', [PlaygroundController::class, 'inventoryReport']);
});

Route::post('/webhooks/{workflow}', WebhookController::class)->middleware('throttle:webhook');
