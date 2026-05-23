<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\RunStatus;
use App\Domain\Workflow\Handlers\StepResult;

readonly class WorkflowExecutionResult
{
    /**
     * @param  array<string, StepResult>  $stepResults
     * @param  array<string, array>  $stepOutputs
     */
    public function __construct(
        public RunStatus $status,
        public array $stepResults = [],
        public array $stepOutputs = [],
        public int $durationMs = 0,
    ) {}
}
