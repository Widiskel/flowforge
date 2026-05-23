<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTriggerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $editor;

    private User $viewer;

    private string $workflowId;

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

        $this->editor = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Editor',
            'email' => 'editor-trigger@test.local',
            'role' => 'editor',
            'password' => bcrypt('password'),
        ]);

        $this->viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer-trigger@test.local',
            'role' => 'viewer',
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
                        'config' => ['operation' => 'set_output', 'output' => ['ready' => true]],
                        'retry' => ['maxAttempts' => 1],
                    ],
                    [
                        'id' => 'finish',
                        'type' => 'SCRIPT',
                        'name' => 'Finish',
                        'dependsOn' => ['init'],
                        'config' => ['operation' => 'noop'],
                        'retry' => ['maxAttempts' => 1],
                    ],
                ],
            ],
        ]);

        $this->workflowId = $response->json('data.id');
    }

    public function test_admin_can_trigger_workflow_and_persist_run_step_runs_and_logs(): void
    {
        $this->actingAs($this->admin, 'api');

        $response = $this->postJson("/api/workflows/{$this->workflowId}/trigger", [
            'input' => ['ticket_id' => 'INC-001'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'SUCCESS');
        $response->assertJsonCount(2, 'data.step_runs');
        $response->assertJsonPath('data.logs.0.event', 'step.completed');

        $this->assertDatabaseHas('workflow_runs', [
            'workflow_id' => $this->workflowId,
            'status' => 'SUCCESS',
        ]);

        $this->assertDatabaseCount('workflow_step_runs', 2);
        $this->assertDatabaseCount('execution_logs', 3);
    }

    public function test_editor_can_trigger_workflow(): void
    {
        $this->actingAs($this->editor, 'api');

        $response = $this->postJson("/api/workflows/{$this->workflowId}/trigger");

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'SUCCESS');
    }

    public function test_viewer_cannot_trigger_workflow(): void
    {
        $this->actingAs($this->viewer, 'api');

        $response = $this->postJson("/api/workflows/{$this->workflowId}/trigger");

        $response->assertStatus(403);
    }

    public function test_run_history_is_tenant_scoped(): void
    {
        $this->actingAs($this->admin, 'api');
        $trigger = $this->postJson("/api/workflows/{$this->workflowId}/trigger");
        $runId = $trigger->json('data.id');

        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-tenant']);
        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Admin',
            'email' => 'other-admin@test.local',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($otherUser, 'api');

        $response = $this->getJson("/api/workflow-runs/{$runId}");

        $response->assertStatus(404);
    }
}
