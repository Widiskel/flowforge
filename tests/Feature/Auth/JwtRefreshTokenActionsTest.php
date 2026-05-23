<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Actions\Auth\IssueJwtTokenPairAction;
use App\Actions\Auth\RevokeJwtRefreshTokenAction;
use App\Actions\Auth\RotateJwtRefreshTokenAction;
use App\Domain\Auth\JwtRefreshTokenStore;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JwtRefreshTokenActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_action_returns_access_and_refresh_token_pair(): void
    {
        $user = $this->makeUser();
        $action = app(IssueJwtTokenPairAction::class);

        $payload = $action->execute($user);

        $this->assertArrayHasKey('access_token', $payload);
        $this->assertArrayHasKey('refresh_token', $payload);
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertDatabaseCount('jwt_refresh_tokens', 1);
    }

    public function test_rotate_action_revokes_old_token_and_issues_new_pair(): void
    {
        $user = $this->makeUser();
        $store = app(JwtRefreshTokenStore::class);
        [$plainToken, $record] = $store->issue($user);

        $payload = app(RotateJwtRefreshTokenAction::class)->execute($plainToken);

        $record->refresh();

        $this->assertNotNull($record->revoked_at);
        $this->assertNotNull($record->replaced_by_id);
        $this->assertArrayHasKey('access_token', $payload);
        $this->assertArrayHasKey('refresh_token', $payload);
        $this->assertDatabaseCount('jwt_refresh_tokens', 2);
    }

    public function test_rotate_action_detects_reuse_and_revokes_all_sessions(): void
    {
        $user = $this->makeUser();
        $store = app(JwtRefreshTokenStore::class);
        [$plainToken, $record] = $store->issue($user);
        [$otherToken] = $store->issue($user);

        $record->forceFill(['revoked_at' => now()])->save();

        try {
            app(RotateJwtRefreshTokenAction::class)->execute($plainToken);
            $this->fail('Expected AuthenticationException was not thrown.');
        } catch (AuthenticationException $exception) {
            $this->assertSame('Refresh token reuse detected. All sessions revoked.', $exception->getMessage());
        }

        $this->assertNull(app(JwtRefreshTokenStore::class)->findUsable($otherToken));
    }

    public function test_revoke_action_marks_token_as_revoked(): void
    {
        $user = $this->makeUser();
        $store = app(JwtRefreshTokenStore::class);
        [$plainToken, $record] = $store->issue($user);

        app(RevokeJwtRefreshTokenAction::class)->execute($plainToken);

        $record->refresh();

        $this->assertNotNull($record->revoked_at);
    }

    private function makeUser(): User
    {
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::lower(Str::random(6)),
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'role' => 'editor',
        ]);
    }
}
