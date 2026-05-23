<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

use App\Domain\Workflow\Enums\StepRunStatus;

readonly class StepResult
{
    public function __construct(
        public StepRunStatus $status,
        public array $output = [],
        public ?string $error = null,
        public int $attemptCount = 1,
    ) {}
}
