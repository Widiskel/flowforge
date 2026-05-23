<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;

class ScriptStepHandler implements StepHandler
{
    private const ALLOWED_OPERATIONS = ['noop', 'set_output', 'transform', 'fail_demo'];

    public function handle(array $config, array $context): StepResult
    {
        $operation = $config['operation'] ?? 'noop';

        if (! in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            return new StepResult(StepRunStatus::FAILED, [], sprintf('Disallowed operation: %s', $operation));
        }

        return match ($operation) {
            'noop' => new StepResult(StepRunStatus::SUCCESS, ['operation' => 'noop']),
            'set_output' => new StepResult(StepRunStatus::SUCCESS, ['output' => $config['output'] ?? null]),
            'transform' => new StepResult(StepRunStatus::SUCCESS, ['transformed' => true]),
            'fail_demo' => new StepResult(StepRunStatus::FAILED, [], 'Intentional failure for demo.'),
        };
    }
}
