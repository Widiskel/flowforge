<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Domain\Workflow\Enums\RunStatus;
use App\Domain\Workflow\Enums\StepRunStatus;
use App\Domain\Workflow\Handlers\StepHandler;
use App\Domain\Workflow\Handlers\StepResult;
use App\Domain\Workflow\Services\WorkflowExecutor;
use Tests\TestCase;

class WorkflowExecutorTest extends TestCase
{
    public function test_executes_linear_workflow_to_success(): void
    {
        $executor = new WorkflowExecutor;
        $executor->registerHandler('SCRIPT', new class implements StepHandler
        {
            public function handle(array $config, array $context): StepResult
            {
                return new StepResult(StepRunStatus::SUCCESS, ['done' => true]);
            }
        });

        $result = $executor->execute([
            'schemaVersion' => 1,
            'name' => 'Test',
            'globalTimeoutMs' => 60000,
            'steps' => [
                ['id' => 'a', 'type' => 'SCRIPT', 'dependsOn' => [], 'config' => ['script' => 'return null;'], 'retry' => ['maxAttempts' => 1]],
                ['id' => 'b', 'type' => 'SCRIPT', 'dependsOn' => ['a'], 'config' => ['script' => 'return null;'], 'retry' => ['maxAttempts' => 1]],
            ],
        ]);

        $this->assertSame(RunStatus::SUCCESS, $result->status);
        $this->assertCount(2, $result->stepResults);
        $this->assertSame(StepRunStatus::SUCCESS, $result->stepResults['a']->status);
        $this->assertSame(StepRunStatus::SUCCESS, $result->stepResults['b']->status);
    }

    public function test_fails_workflow_on_step_failure(): void
    {
        $executor = new WorkflowExecutor;
        $executor->registerHandler('SCRIPT', new class implements StepHandler
        {
            public function handle(array $config, array $context): StepResult
            {
                if (str_contains((string) ($config['script'] ?? ''), 'fail')) {
                    return new StepResult(StepRunStatus::FAILED, [], 'Intentional failure.');
                }

                return new StepResult(StepRunStatus::SUCCESS);
            }
        });

        $result = $executor->execute([
            'schemaVersion' => 1,
            'name' => 'Fail Test',
            'globalTimeoutMs' => 60000,
            'steps' => [
                ['id' => 'ok', 'type' => 'SCRIPT', 'dependsOn' => [], 'config' => ['script' => 'return null;'], 'retry' => ['maxAttempts' => 1]],
                ['id' => 'bad', 'type' => 'SCRIPT', 'dependsOn' => ['ok'], 'config' => ['script' => 'fail'], 'retry' => ['maxAttempts' => 1]],
                ['id' => 'never', 'type' => 'SCRIPT', 'dependsOn' => ['bad'], 'config' => ['script' => 'return null;'], 'retry' => ['maxAttempts' => 1]],
            ],
        ]);

        $this->assertSame(RunStatus::FAILED, $result->status);
        $this->assertSame(StepRunStatus::SUCCESS, $result->stepResults['ok']->status);
        $this->assertSame(StepRunStatus::FAILED, $result->stepResults['bad']->status);
        $this->assertArrayNotHasKey('never', $result->stepResults);
    }

    public function test_retries_step_before_failing(): void
    {
        $attempts = 0;
        $executor = new WorkflowExecutor;
        $executor->registerHandler('SCRIPT', new class($attempts) implements StepHandler
        {
            private int $attempts;

            public function __construct(int &$attempts)
            {
                $this->attempts = &$attempts;
            }

            public function handle(array $config, array $context): StepResult
            {
                $this->attempts++;
                if ($this->attempts < 3) {
                    return new StepResult(StepRunStatus::FAILED, [], 'Not yet.');
                }

                return new StepResult(StepRunStatus::SUCCESS, ['attempt' => $this->attempts]);
            }
        });

        $result = $executor->execute([
            'schemaVersion' => 1,
            'name' => 'Retry Test',
            'globalTimeoutMs' => 60000,
            'steps' => [
                ['id' => 'retry_me', 'type' => 'SCRIPT', 'dependsOn' => [], 'config' => [], 'retry' => ['maxAttempts' => 3, 'initialDelayMs' => 1, 'backoff' => 'exponential']],
            ],
        ]);

        $this->assertSame(RunStatus::SUCCESS, $result->status);
        $this->assertSame(3, $attempts);
    }

    public function test_skips_downstream_when_condition_skipped(): void
    {
        $executor = new WorkflowExecutor;

        $result = $executor->execute([
            'schemaVersion' => 1,
            'name' => 'Condition Skip',
            'globalTimeoutMs' => 60000,
            'steps' => [
                ['id' => 'check', 'type' => 'CONDITION', 'dependsOn' => [], 'config' => ['field' => 'missing', 'operator' => 'equals', 'value' => 'x'], 'retry' => ['maxAttempts' => 1]],
                ['id' => 'after', 'type' => 'SCRIPT', 'dependsOn' => ['check'], 'config' => ['script' => 'return null;'], 'retry' => ['maxAttempts' => 1]],
            ],
        ]);

        $this->assertSame(RunStatus::SUCCESS, $result->status);
        $this->assertSame(StepRunStatus::SKIPPED, $result->stepResults['check']->status);
        $this->assertSame(StepRunStatus::SKIPPED, $result->stepResults['after']->status);
    }

    public function test_global_timeout_enforced(): void
    {
        $executor = new WorkflowExecutor;
        $executor->registerHandler('DELAY', new class implements StepHandler
        {
            public function handle(array $config, array $context): StepResult
            {
                usleep(50000); // 50ms

                return new StepResult(StepRunStatus::SUCCESS);
            }
        });

        $result = $executor->execute([
            'schemaVersion' => 1,
            'name' => 'Timeout Test',
            'globalTimeoutMs' => 1, // 1ms — will timeout immediately on second step
            'steps' => [
                ['id' => 'slow1', 'type' => 'DELAY', 'dependsOn' => [], 'config' => ['durationMs' => 50], 'retry' => ['maxAttempts' => 1]],
                ['id' => 'slow2', 'type' => 'DELAY', 'dependsOn' => ['slow1'], 'config' => ['durationMs' => 50], 'retry' => ['maxAttempts' => 1]],
            ],
        ]);

        $this->assertSame(RunStatus::TIMEOUT, $result->status);
    }
}
