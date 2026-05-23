<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class JwtClaimsAndRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_jwt_access_token_contains_tenant_id_and_role_claims(): void
    {
        $user = $this->makeUser('admin@flowforge.test', 'admin');

        $token = JWTAuth::fromUser($user);
        $payload = JWTAuth::setToken($token)->getPayload();

        $this->assertSame($user->tenant_id, $payload->get('tenant_id'));
        $this->assertSame('admin', $payload->get('role'));
        $this->assertNotNull($payload->get('jti'));
    }

    public function test_jwt_claims_reflect_correct_role_per_user(): void
    {
        $editor = $this->makeUser('editor@flowforge.test', 'editor');
        $viewer = $this->makeUser('viewer@flowforge.test', 'viewer');

        $editorPayload = JWTAuth::setToken(JWTAuth::fromUser($editor))->getPayload();
        $viewerPayload = JWTAuth::setToken(JWTAuth::fromUser($viewer))->getPayload();

        $this->assertSame('editor', $editorPayload->get('role'));
        $this->assertSame('viewer', $viewerPayload->get('role'));
    }

    public function test_user_has_role_helper(): void
    {
        $admin = $this->makeUser('admin@flowforge.test', 'admin');
        $editor = $this->makeUser('editor@flowforge.test', 'editor');
        $viewer = $this->makeUser('viewer@flowforge.test', 'viewer');

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertFalse($admin->hasRole('editor', 'viewer'));

        $this->assertTrue($editor->hasRole('editor'));
        $this->assertTrue($editor->hasRole('admin', 'editor'));
        $this->assertFalse($editor->hasRole('viewer'));

        $this->assertTrue($viewer->hasRole('viewer'));
        $this->assertFalse($viewer->hasRole('admin', 'editor'));
    }

    public function test_me_endpoint_returns_401_without_bearer_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_endpoint_returns_401_without_bearer_token(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertUnauthorized();
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
