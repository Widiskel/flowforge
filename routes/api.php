<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Monitoring\HealthMetricsController;
use App\Http\Controllers\Workflow\ExecutionLogController;
use App\Http\Controllers\Workflow\RunEventStreamController;
use App\Http\Controllers\Workflow\WebhookController;
use App\Http\Controllers\Workflow\WorkflowController;
use App\Http\Controllers\Workflow\WorkflowRunController;
use App\Http\Controllers\Workflow\WorkflowTriggerController;
use App\Http\Controllers\Workflow\WorkflowVersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:api');
});

Route::middleware(['auth:api', 'tenant'])->group(function (): void {
    Route::get('/workflows', [WorkflowController::class, 'index']);
    Route::post('/workflows', [WorkflowController::class, 'store']);
    Route::get('/workflows/{workflow}', [WorkflowController::class, 'show']);
    Route::put('/workflows/{workflow}', [WorkflowController::class, 'update']);
    Route::delete('/workflows/{workflow}', [WorkflowController::class, 'destroy']);

    Route::get('/workflows/{workflow}/versions', [WorkflowVersionController::class, 'index']);
    Route::post('/workflows/{workflow}/rollback/{version}', [WorkflowVersionController::class, 'rollback']);
    Route::get('/workflows/{workflow}/triggers', [WorkflowTriggerController::class, 'index']);
    Route::post('/workflows/{workflow}/triggers', [WorkflowTriggerController::class, 'store']);

    Route::post('/workflows/{workflow}/trigger', [WorkflowRunController::class, 'trigger']);

    Route::get('/workflow-runs', [WorkflowRunController::class, 'index']);
    Route::get('/workflow-runs/{run}', [WorkflowRunController::class, 'show']);
    Route::get('/workflow-runs/{run}/events', [RunEventStreamController::class, '__invoke']);
    Route::get('/workflow-runs/{run}/logs', [ExecutionLogController::class, '__invoke']);
    Route::get('/health/metrics', [HealthMetricsController::class, '__invoke']);
});

Route::post('/webhooks/{workflow}', WebhookController::class);
