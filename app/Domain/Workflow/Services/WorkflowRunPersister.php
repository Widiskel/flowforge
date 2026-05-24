<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\RunStatus;
use App\Domain\Workflow\Enums\StepRunStatus;
use App\Models\ExecutionLog;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowRunPersister
{
    public function persist(WorkflowRun $run, array $definition, WorkflowExecutionResult $result): WorkflowRun
    {
        return DB::transaction(function () use ($run, $definition, $result): WorkflowRun {
            $now = now();

            $run->update([
                'status' => $result->status->value,
                'started_at' => $run->started_at ?? $now,
                'finished_at' => $now,
                'duration_ms' => $result->durationMs,
            ]);

            $stepsById = [];
            foreach ($definition['steps'] as $step) {
                $stepsById[$step['id']] = $step;
            }

            foreach ($result->stepResults as $stepId => $stepResult) {
                $step = $stepsById[$stepId] ?? null;
                if (! $step) {
                    continue;
                }

                $stepRun = WorkflowStepRun::query()->create([
                    'tenant_id' => $run->tenant_id,
                    'workflow_run_id' => $run->id,
                    'step_id' => $stepId,
                    'step_type' => $step['type'],
                    'status' => $stepResult->status->value,
                    'attempt_count' => $stepResult->attemptCount ?? 1,
                    'max_attempts' => $step['retry']['maxAttempts'] ?? 1,
                    'started_at' => $now,
                    'finished_at' => $now,
                    'duration_ms' => $stepResult->durationMs ?? 0,
                    'output' => $stepResult->output,
                    'error_message' => $stepResult->error,
                ]);

                // LOG step: surface the user-authored message as a first-class
                // execution log entry so it shows up in the live logs panel,
                // not buried inside the step output blob.
                if (
                    ($step['type'] ?? null) === 'LOG'
                    && $stepResult->status === StepRunStatus::SUCCESS
                    && is_array($stepResult->output)
                    && isset($stepResult->output['message'])
                ) {
                    $level = is_string($stepResult->output['level'] ?? null)
                        ? strtolower((string) $stepResult->output['level'])
                        : 'info';

                    ExecutionLog::query()->create([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $run->tenant_id,
                        'workflow_run_id' => $run->id,
                        'workflow_step_run_id' => $stepRun->id,
                        'level' => in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true) ? $level : 'info',
                        'event' => 'log.step',
                        'message' => (string) $stepResult->output['message'],
                        'context' => is_array($stepResult->output['context'] ?? null) ? $stepResult->output['context'] : null,
                        'created_at' => $now,
                    ]);
                }

                ExecutionLog::query()->create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $run->tenant_id,
                    'workflow_run_id' => $run->id,
                    'workflow_step_run_id' => $stepRun->id,
                    'level' => $stepResult->status === StepRunStatus::FAILED ? 'error' : 'info',
                    'event' => 'step.completed',
                    'message' => sprintf('Step %s finished with status %s', $stepId, $stepResult->status->value),
                    'context' => ['output' => $stepResult->output, 'error' => $stepResult->error],
                    'created_at' => $now,
                ]);
            }

            ExecutionLog::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $run->tenant_id,
                'workflow_run_id' => $run->id,
                'workflow_step_run_id' => null,
                'level' => $result->status === RunStatus::SUCCESS ? 'info' : 'error',
                'event' => 'run.completed',
                'message' => sprintf('Run finished with status %s in %d ms', $result->status->value, $result->durationMs),
                'context' => null,
                'created_at' => $now,
            ]);

            return $run->fresh(['stepRuns', 'logs']);
        });
    }
}
