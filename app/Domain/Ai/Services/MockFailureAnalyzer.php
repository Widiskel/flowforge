<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

class MockFailureAnalyzer
{
    public function analyze(array $context): array
    {
        $logs = $context['logs'] ?? [];
        $stepRuns = $context['step_runs'] ?? [];

        $errorLog = collect($logs)->firstWhere('level', 'error');
        $failedStep = collect($stepRuns)->firstWhere('status', 'FAILED');

        $message = $errorLog['message'] ?? $failedStep['error_message'] ?? 'Workflow run failed without explicit error message.';
        $stepId = $failedStep['step_id'] ?? 'unknown-step';

        return [
            'root_cause' => "Kegagalan paling mungkin terjadi di step {$stepId}: {$message}",
            'suggested_fix' => 'Periksa konfigurasi step, input tenant-scoped, dan dependency step sebelumnya. Ulangi run setelah error source diperbaiki.',
            'confidence' => $errorLog !== null || $failedStep !== null ? 'medium' : 'low',
            'category' => 'workflow_execution_failure',
            'evidence' => array_values(array_filter([
                $failedStep !== null ? [
                    'observation' => "Step {$stepId} berstatus FAILED",
                    'source' => 'step_runs',
                ] : null,
                $errorLog !== null ? [
                    'observation' => $message,
                    'source' => 'execution_logs',
                ] : null,
            ])),
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'provider' => 'mock',
            'model' => 'deterministic-mock',
        ];
    }
}
