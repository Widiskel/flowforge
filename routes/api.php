<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Workflow\WorkflowController;
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
});
