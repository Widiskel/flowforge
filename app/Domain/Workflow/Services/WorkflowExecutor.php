<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\RunStatus;
use App\Domain\Workflow\Enums\StepRunStatus;
use App\Domain\Workflow\Handlers\ConditionStepHandler;
use App\Domain\Workflow\Handlers\DelayStepHandler;
use App\Domain\Workflow\Handlers\HttpStepHandler;
use App\Domain\Workflow\Handlers\ScriptStepHandler;
use App\Domain\Workflow\Handlers\StepHandler;
use App\Domain\Workflow\Handlers\StepResult;

class WorkflowExecutor
{
    private array $handlers = [];

    public function __construct()
    {
        $this->handlers = [
            'HTTP' => new HttpStepHandler,
            'DELAY' => new DelayStepHandler,
            'CONDITION' => new ConditionStepHandler,
            'SCRIPT' => new ScriptStepHandler,
        ];
    }

    public function registerHandler(string $type, StepHandler $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function execute(array $definition): WorkflowExecutionResult
    {
        $planner = new ExecutionPlanner;
        $batches = $planner->planBatches($definition);

        $stepOutputs = [];
        $stepResults = [];
        $startedAt = microtime(true);
        $globalTimeoutMs = $definition['globalTimeoutMs'] ?? 300000;

        $stepsById = [];
        foreach ($definition['steps'] as $step) {
            $stepsById[$step['id']] = $step;
        }

        foreach ($batches as $batch) {
            foreach ($batch as $stepId) {
                $elapsed = (microtime(true) - $startedAt) * 1000;
                if ($elapsed >= $globalTimeoutMs) {
                    return new WorkflowExecutionResult(
                        RunStatus::TIMEOUT,
                        $stepResults,
                        $stepOutputs,
                        (int) $elapsed,
                    );
                }

                $step = $stepsById[$stepId];
                $handler = $this->handlers[$step['type']] ?? null;

                if (! $handler) {
                    $stepResults[$stepId] = new StepResult(StepRunStatus::FAILED, [], 'No handler for type: '.$step['type']);

                    return new WorkflowExecutionResult(RunStatus::FAILED, $stepResults, $stepOutputs, (int) ((microtime(true) - $startedAt) * 1000));
                }

                // Check if dependencies were skipped (condition false)
                foreach (($step['dependsOn'] ?? []) as $dep) {
                    if (isset($stepResults[$dep]) && $stepResults[$dep]->status === StepRunStatus::SKIPPED) {
                        $stepResults[$stepId] = new StepResult(StepRunStatus::SKIPPED, [], 'Dependency skipped.');

                        continue 2;
                    }
                }

                $result = $this->executeStepWithRetry($handler, $step, $stepOutputs);
                $stepResults[$stepId] = $result;
                $stepOutputs[$stepId] = $result->output;

                if ($result->status === StepRunStatus::FAILED) {
                    return new WorkflowExecutionResult(
                        RunStatus::FAILED,
                        $stepResults,
                        $stepOutputs,
                        (int) ((microtime(true) - $startedAt) * 1000),
                    );
                }
            }
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        return new WorkflowExecutionResult(RunStatus::SUCCESS, $stepResults, $stepOutputs, $durationMs);
    }

    private function executeStepWithRetry(StepHandler $handler, array $step, array $context): StepResult
    {
        $maxAttempts = $step['retry']['maxAttempts'] ?? 1;
        $initialDelayMs = $step['retry']['initialDelayMs'] ?? 1000;
        $backoff = $step['retry']['backoff'] ?? 'exponential';

        $lastResult = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $lastResult = $handler->handle($step['config'] ?? [], $context);

            if ($lastResult->status !== StepRunStatus::FAILED || $attempt >= $maxAttempts) {
                break;
            }

            $delay = $backoff === 'exponential'
                ? $initialDelayMs * (2 ** ($attempt - 1))
                : $initialDelayMs;

            usleep(min($delay, 10000) * 1000); // cap at 10s for safety
        }

        return $lastResult;
    }
}
