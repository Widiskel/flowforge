<?php

declare(strict_types=1);

namespace App\GraphQL;

use App\Models\User;

final class Context
{
    public function __construct(
        public readonly User $user,
    ) {}
}
