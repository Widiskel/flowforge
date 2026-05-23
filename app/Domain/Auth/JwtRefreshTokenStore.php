<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Models\JwtRefreshToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class JwtRefreshTokenStore
{
    public function issue(User $user, ?CarbonImmutable $expiresAt = null): array
    {
        $plainToken = Str::random(80);

        $record = JwtRefreshToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hash($plainToken),
            'expires_at' => $expiresAt ?? now()->toImmutable()->addMinutes($this->refreshTtlMinutes()),
        ]);

        return [$plainToken, $record];
    }

    public function findUsable(string $plainToken): ?JwtRefreshToken
    {
        /** @var JwtRefreshToken|null $record */
        $record = JwtRefreshToken::query()
            ->where('token_hash', $this->hash($plainToken))
            ->first();

        if ($record === null || ! $record->isUsable()) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->save();

        return $record;
    }

    public function rotate(string $plainToken): array
    {
        return DB::transaction(function () use ($plainToken): array {
            $current = $this->findUsable($plainToken);

            if ($current === null) {
                throw new RuntimeException('Refresh token is invalid, revoked, or expired.');
            }

            [$nextPlainToken, $nextRecord] = $this->issue($current->user);

            $current->forceFill([
                'revoked_at' => now(),
                'replaced_by_id' => $nextRecord->getKey(),
            ])->save();

            return [$nextPlainToken, $nextRecord, $current->user];
        });
    }

    public function revoke(string $plainToken): void
    {
        JwtRefreshToken::query()
            ->where('token_hash', $this->hash($plainToken))
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(User $user): int
    {
        return JwtRefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function refreshTtlMinutes(): int
    {
        return (int) config('auth.jwt.refresh_ttl', 20160);
    }
}
