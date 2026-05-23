<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Handlers;

interface StepHandler
{
    public function handle(array $config, array $context): StepResult;
}
