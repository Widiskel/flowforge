<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;

class ConditionStepHandler implements StepHandler
{
    public function handle(array $config, array $context): StepResult
    {
        $field = $config['field'] ?? null;
        $operator = $config['operator'] ?? 'equals';
        $value = $config['value'] ?? null;

        $actual = data_get($context, $field);

        $passed = match ($operator) {
            'equals' => $actual == $value,
            'not_equals' => $actual != $value,
            'contains' => is_string($actual) && str_contains($actual, (string) $value),
            'greater_than' => is_numeric($actual) && $actual > $value,
            'less_than' => is_numeric($actual) && $actual < $value,
            default => false,
        };

        return new StepResult(
            $passed ? StepRunStatus::SUCCESS : StepRunStatus::SKIPPED,
            ['evaluated' => $passed],
        );
    }
}
