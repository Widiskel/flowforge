<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SimulateStepTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo']);
        $this->admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/workflows/simulate-step', [
            'type' => 'SCRIPT',
            'config' => ['script' => 'return null;'],
        ]);

        $response->assertStatus(401);
    }

    public function test_invalid_type_is_rejected_with_validation_error(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/workflows/simulate-step', [
            'type' => 'EMAIL',
            'config' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_simulating_script_returns_success_payload(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/workflows/simulate-step', [
            'type' => 'SCRIPT',
            'config' => ['script' => 'return { ok: true };'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUCCESS');
        $response->assertJsonPath('data.error', null);
        $response->assertJsonPath('data.output.output.ok', true);
    }

    public function test_simulating_script_thrown_error_returns_failed(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/workflows/simulate-step', [
            'type' => 'SCRIPT',
            'config' => ['script' => 'throw new Error("boom");'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'FAILED');
        $response->assertJsonPath('data.error', 'boom');
    }

    public function test_simulating_http_uses_handler_stack(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(['greeting' => 'hi'], 200, ['Content-Type' => 'application/json']),
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/api/workflows/simulate-step', [
            'type' => 'HTTP',
            'config' => [
                'method' => 'GET',
                'url' => 'https://api.example.com/echo',
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUCCESS');
        $response->assertJsonPath('data.output.status', 200);
    }

    public function test_simulating_condition_evaluates_input_context(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/workflows/simulate-step', [
            'type' => 'CONDITION',
            'config' => [
                'field' => 'fetch.status',
                'operator' => 'equals',
                'value' => 200,
            ],
            'input' => [
                'fetch' => ['status' => 200],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUCCESS');
        $response->assertJsonPath('data.output.evaluated', true);
    }
}
