<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Auth\JwtRefreshTokenStore;
use App\Models\JwtRefreshToken;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class RotateJwtRefreshTokenAction
{
    public function __construct(
        private readonly JwtRefreshTokenStore $refreshTokenStore,
    ) {}

    /**
     * Rotate a refresh token: revoke old, issue new pair.
     * If the token was already revoked (reuse attempt), blacklist the entire user chain.
     *
     * @throws AuthenticationException
     */
    public function execute(string $plainRefreshToken): array
    {
        $existing = JwtRefreshToken::query()
            ->where('token_hash', $this->refreshTokenStore->hash($plainRefreshToken))
            ->first();

        if ($existing === null) {
            throw new AuthenticationException('Refresh token not found.');
        }

        // Reuse detection: if token is already revoked, someone stole it.
        // Blacklist all active tokens for this user as defense-in-depth.
        if ($existing->isRevoked()) {
            $this->refreshTokenStore->revokeAllForUser($existing->user);

            throw new AuthenticationException('Refresh token reuse detected. All sessions revoked.');
        }

        if ($existing->isExpired()) {
            throw new AuthenticationException('Refresh token expired.');
        }

        // Normal rotation
        [$newRefreshToken, $newRecord] = $this->refreshTokenStore->issue($existing->user);

        $existing->forceFill([
            'revoked_at' => now(),
            'replaced_by_id' => $newRecord->getKey(),
        ])->save();

        /** @var User $user */
        $user = $existing->user;
        $accessToken = JWTAuth::fromUser($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
        ];
    }
}
