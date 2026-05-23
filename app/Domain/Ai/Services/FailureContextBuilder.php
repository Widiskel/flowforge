<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Models\WorkflowRun;

class FailureContextBuilder
{
    private const SECRET_KEYS_PATTERN = '/authorization|cookie|token|password|secret|api[_-]?key/i';

    private const MAX_STRING_LENGTH = 300;

    public function build(WorkflowRun $run): array
    {
        $run->loadMissing(['workflow', 'stepRuns', 'logs']);

        return [
            'workflow' => [
                'id' => $run->workflow?->id,
                'name' => $run->workflow?->name,
            ],
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'input' => $this->sanitizeValue($run->input ?? []),
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'duration_ms' => $run->duration_ms,
            ],
            'step_runs' => $run->stepRuns->map(fn ($stepRun) => [
                'id' => $stepRun->id,
                'step_id' => $stepRun->step_id,
                'step_type' => $stepRun->step_type,
                'status' => $stepRun->status,
                'attempt_count' => $stepRun->attempt_count,
                'output' => $this->sanitizeValue($stepRun->output),
                'error_message' => $this->sanitizeString($stepRun->error_message),
            ])->values()->all(),
            'logs' => $run->logs->take(20)->map(fn ($log) => [
                'level' => $log->level,
                'event' => $log->event,
                'message' => $this->sanitizeString($log->message),
                'context' => $this->sanitizeValue($log->context),
            ])->values()->all(),
        ];
    }

    public function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SECRET_KEYS_PATTERN, $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    public function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/(authorization|cookie|token|password|secret|api[_-]?key)\s*[:=]\s*([^\s,;]+)/i', '$1=[REDACTED]', $value) ?? $value;

        if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
            return mb_substr($value, 0, self::MAX_STRING_LENGTH).' …[TRUNCATED]';
        }

        return $value;
    }
}
