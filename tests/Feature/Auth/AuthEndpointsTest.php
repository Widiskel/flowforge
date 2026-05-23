<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\JwtRefreshTokenStore;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_jwt_token_pair_and_user_context(): void
    {
        $user = $this->makeUser('editor@flowforge.test', 'editor');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.tokenType', 'Bearer')
            ->assertJsonPath('user.email', 'editor@flowforge.test')
            ->assertJsonPath('user.role', 'editor')
            ->assertJsonPath('user.tenant.slug', $user->tenant->slug)
            ->assertJsonStructure([
                'data' => ['accessToken', 'refreshToken', 'tokenType', 'expiresIn'],
            ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = $this->makeUser('editor@flowforge.test', 'editor');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_me_returns_current_user_from_bearer_token(): void
    {
        $user = $this->makeUser('admin@flowforge.test', 'admin');
        $tokens = $this->loginAs($user);

        $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@flowforge.test')
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_refresh_rotates_refresh_token(): void
    {
        $user = $this->makeUser('editor@flowforge.test', 'editor');
        $tokens = $this->loginAs($user);

        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $tokens['refreshToken'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.tokenType', 'Bearer')
            ->assertJsonStructure(['data' => ['accessToken', 'refreshToken']]);

        $this->assertNotSame($tokens['refreshToken'], $response->json('data.refreshToken'));
    }

    public function test_refresh_reuse_revokes_all_sessions(): void
    {
        $user = $this->makeUser('editor@flowforge.test', 'editor');
        $store = app(JwtRefreshTokenStore::class);
        [$plainToken, $record] = $store->issue($user);
        [$otherToken] = $store->issue($user);

        $record->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $plainToken,
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Refresh token reuse detected. All sessions revoked.');

        $this->assertNull($store->findUsable($otherToken));
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $user = $this->makeUser('viewer@flowforge.test', 'viewer');
        $tokens = $this->loginAs($user);

        $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])
            ->postJson('/api/auth/logout', [
                'refresh_token' => $tokens['refreshToken'],
            ])->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertDatabaseHas('jwt_refresh_tokens', [
            'token_hash' => app(JwtRefreshTokenStore::class)->hash($tokens['refreshToken']),
        ]);

        $this->assertNull(app(JwtRefreshTokenStore::class)->findUsable($tokens['refreshToken']));
    }

    private function loginAs(User $user): array
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data');
    }

    private function makeUser(string $email, string $role): User
    {
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tenant '.$role,
            'slug' => 'tenant-'.$role.'-'.Str::lower(Str::random(6)),
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'email' => $email,
            'role' => $role,
            'password' => Hash::make('password'),
        ]);
    }
}
