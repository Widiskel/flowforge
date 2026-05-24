<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;
use Illuminate\Support\Facades\Log;

/**
 * LOG step — writes a structured line into the application log channel.
 *
 * Config shape:
 *   - level   : debug | info | notice | warning | error | critical (default info)
 *   - message : freeform string. Supports {{stepId.path.to.field}} placeholders
 *               that resolve against the upstream context.
 *   - context : optional array of extra fields, also placeholder-substituted.
 *
 * The resolved line is written to the configured Laravel log channel and is
 * also surfaced as the step output so downstream steps can chain on it.
 */
class LogStepHandler implements StepHandler
{
    private const ALLOWED_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    public function handle(array $config, array $context): StepResult
    {
        $level = strtolower((string) ($config['level'] ?? 'info'));
        if (! in_array($level, self::ALLOWED_LEVELS, true)) {
            return new StepResult(StepRunStatus::FAILED, [], sprintf(
                'Unsupported log level "%s"; allowed: %s.',
                $level,
                implode(', ', self::ALLOWED_LEVELS),
            ));
        }

        $rawMessage = (string) ($config['message'] ?? '');
        if (trim($rawMessage) === '') {
            return new StepResult(StepRunStatus::FAILED, [], 'LOG step requires a non-empty `message` string.');
        }

        $resolvedMessage = $this->renderTemplate($rawMessage, $context);
        $resolvedContext = $this->renderContextArray($config['context'] ?? [], $context);

        Log::log($level, '[workflow] '.$resolvedMessage, $resolvedContext);

        return new StepResult(StepRunStatus::SUCCESS, [
            'level' => $level,
            'message' => $resolvedMessage,
            'context' => $resolvedContext,
        ]);
    }

    /**
     * Replace `{{path.into.context}}` placeholders inside a string with the
     * resolved value. Missing paths render as the empty string so logs stay
     * readable instead of leaking the placeholder syntax.
     */
    private function renderTemplate(string $template, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.\-]+)\s*\}\}/',
            function (array $match) use ($context): string {
                $value = data_get($context, $match[1]);
                if ($value === null) {
                    return '';
                }
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            },
            $template,
        );
    }

    /**
     * Render template strings inside an associative context array. Non-string
     * values pass through untouched so callers can attach numbers, booleans,
     * or nested arrays without surprises.
     */
    private function renderContextArray(mixed $rawContext, array $upstream): array
    {
        if (! is_array($rawContext)) {
            return [];
        }

        $rendered = [];
        foreach ($rawContext as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $rendered[$key] = is_string($value) ? $this->renderTemplate($value, $upstream) : $value;
        }

        return $rendered;
    }
}
