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

        $actual = data_get($context, $field);

        // Support comparing one context field against another (e.g. compare
        // outputs of two SCRIPT steps in the seeded sum-vs-diff demo). When
        // `value_field` is set we resolve it through `data_get` too; otherwise
        // we fall back to the literal `value`. Numeric strings are coerced so
        // a JSON `"10"` from an HTTP step still compares correctly.
        $valueField = $config['value_field'] ?? null;
        $value = $valueField !== null
            ? data_get($context, $valueField)
            : ($config['value'] ?? null);

        $passed = match ($operator) {
            'equals' => $this->loose($actual) == $this->loose($value),
            'not_equals' => $this->loose($actual) != $this->loose($value),
            'contains' => is_string($actual) && str_contains($actual, (string) $value),
            'greater_than' => is_numeric($actual) && is_numeric($value) && (float) $actual > (float) $value,
            'less_than' => is_numeric($actual) && is_numeric($value) && (float) $actual < (float) $value,
            'greater_or_equal' => is_numeric($actual) && is_numeric($value) && (float) $actual >= (float) $value,
            'less_or_equal' => is_numeric($actual) && is_numeric($value) && (float) $actual <= (float) $value,
            default => false,
        };

        return new StepResult(
            $passed ? StepRunStatus::SUCCESS : StepRunStatus::SKIPPED,
            [
                'evaluated' => $passed,
                'actual' => $actual,
                'value' => $value,
                'operator' => $operator,
            ],
        );
    }

    /**
     * Coerce numeric strings so JSON values like "10" compare equal to int 10.
     */
    private function loose(mixed $v): mixed
    {
        if (is_string($v) && is_numeric($v)) {
            return $v + 0;
        }

        return $v;
    }
}
