<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Auth\JwtRefreshTokenStore;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class IssueJwtTokenPairAction
{
    public function __construct(
        private readonly JwtRefreshTokenStore $refreshTokenStore,
    ) {}

    public function execute(User $user): array
    {
        $accessToken = JWTAuth::fromUser($user);

        [$refreshToken] = $this->refreshTokenStore->issue($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }
}
