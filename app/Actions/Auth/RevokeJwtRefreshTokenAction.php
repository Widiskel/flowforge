<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Auth\JwtRefreshTokenStore;

class RevokeJwtRefreshTokenAction
{
    public function __construct(
        private readonly JwtRefreshTokenStore $refreshTokenStore,
    ) {}

    public function execute(?string $plainRefreshToken): void
    {
        if ($plainRefreshToken === null || $plainRefreshToken === '') {
            return;
        }

        $this->refreshTokenStore->revoke($plainRefreshToken);
    }
}
