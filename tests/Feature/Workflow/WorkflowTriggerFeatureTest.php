<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTriggerFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Trigger Tenant', 'slug' => 'trigger-tenant']);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin-trigger@test.local',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($this->admin, 'api');

        $response = $this->postJson('/api/workflows', [
            'name' => 'Triggerable Workflow',
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'Triggerable Workflow',
                'globalTimeoutMs' => 60000,
                'steps' => [
                    [
                        'id' => 'init',
                        'type' => 'SCRIPT',
                        'name' => 'Init',
                        'dependsOn' => [],
                        'config' => ['operation' => 'noop'],
                        'retry' => ['maxAttempts' => 1],
                    ],
                ],
            ],
        ]);

        $this->workflow = Workflow::find($response->json('data.id'));
    }

    public function test_list_triggers_returns_tenant_scoped(): void
    {
        WorkflowTrigger::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $this->workflow->id,
            'type' => 'scheduled',
            'cron_expression' => '* * * * *',
            'enabled' => true,
            'created_by' => $this->admin->id,
        ]);

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherWorkflow = Workflow::create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $this->admin->id,
            'name' => 'Other WF',
            'status' => 'draft',
        ]);
        WorkflowTrigger::create([
            'tenant_id' => $otherTenant->id,
            'workflow_id' => $otherWorkflow->id,
            'type' => 'scheduled',
            'cron_expression' => '* * * * *',
            'enabled' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/workflows/{$this->workflow->id}/triggers");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.workflow_id', $this->workflow->id);
    }

    public function test_create_scheduled_trigger(): void
    {
        $response = $this->postJson("/api/workflows/{$this->workflow->id}/triggers", [
            'type' => 'scheduled',
            'cron_expression' => '0 * * * *',
            'timezone' => 'Asia/Jakarta',
            'enabled' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'scheduled');
        $response->assertJsonPath('data.cron_expression', '0 * * * *');
        $response->assertJsonPath('data.timezone', 'Asia/Jakarta');
        $response->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('workflow_triggers', [
            'workflow_id' => $this->workflow->id,
            'type' => 'scheduled',
            'cron_expression' => '0 * * * *',
        ]);
    }

    public function test_create_webhook_trigger(): void
    {
        $response = $this->postJson("/api/workflows/{$this->workflow->id}/triggers", [
            'type' => 'webhook',
            'webhook_secret' => 'my-secret-key',
            'enabled' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'webhook');
        $response->assertJsonPath('data.webhook_secret', '***');
    }

    public function test_create_trigger_rejects_invalid_cron(): void
    {
        $response = $this->postJson("/api/workflows/{$this->workflow->id}/triggers", [
            'type' => 'scheduled',
            'cron_expression' => 'invalid cron',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cron_expression']);
    }

    public function test_webhook_valid_signature_accepted(): void
    {
        $trigger = WorkflowTrigger::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $this->workflow->id,
            'type' => 'webhook',
            'webhook_secret' => 'my-secret',
            'enabled' => true,
            'created_by' => $this->admin->id,
        ]);

        $payload = ['test' => 'data'];
        $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'my-secret');

        $response = $this->withHeaders([
            'X-FlowForge-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->postJson("/api/webhooks/{$this->workflow->id}", $payload);

        $response->assertStatus(202);
        $this->assertDatabaseCount('workflow_runs', 1);
    }

    public function test_webhook_invalid_signature_rejected(): void
    {
        $trigger = WorkflowTrigger::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $this->workflow->id,
            'type' => 'webhook',
            'webhook_secret' => 'my-secret',
            'enabled' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->withHeaders([
            'X-FlowForge-Signature' => 'sha256=wrong',
        ])->postJson("/api/webhooks/{$this->workflow->id}", []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Invalid signature');
        $this->assertDatabaseCount('workflow_runs', 0);
    }
}
