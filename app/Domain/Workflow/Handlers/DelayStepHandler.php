<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;

class DelayStepHandler implements StepHandler
{
    private const MAX_DELAY_SECONDS = 30;

    public function handle(array $config, array $context): StepResult
    {
        $durationMs = min(($config['durationMs'] ?? 1000), self::MAX_DELAY_SECONDS * 1000);

        usleep($durationMs * 1000);

        return new StepResult(StepRunStatus::SUCCESS, ['delayed_ms' => $durationMs]);
    }
}
