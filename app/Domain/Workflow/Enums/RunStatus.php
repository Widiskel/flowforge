<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Enums;

enum RunStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
    case TIMEOUT = 'TIMEOUT';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCESS, self::FAILED, self::TIMEOUT], true);
    }
}
