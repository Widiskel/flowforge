<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Enums;

enum StepType: string
{
    case HTTP = 'HTTP';
    case DELAY = 'DELAY';
    case CONDITION = 'CONDITION';
    case SCRIPT = 'SCRIPT';
}
