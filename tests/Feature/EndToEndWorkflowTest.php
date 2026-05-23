<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExecutionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkflowWithVersion(Tenant $tenant, User $owner): Workflow
    {
        $workflow = Workflow::factory()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $owner->id,
            'status' => 'active',
        ]);

        $version = WorkflowVersion::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'version_number' => 1,
            'definition' => [
                'schemaVersion' => 1,
                'name' => $workflow->name,
                'globalTimeoutMs' => 60000,
                'steps' => [
                    [
                        'id' => 'step_1',
                        'type' => 'SCRIPT',
                        'name' => 'Noop step',
                        'dependsOn' => [],
                        'config' => ['operation' => 'noop'],
                    ],
                ],
            ],
            'source' => 'manual_update',
            'created_by' => $owner->id,
        ]);

        $workflow->update(['current_version_id' => $version->id]);

        return $workflow->fresh();
    }

    public function test_full_workflow_run_lifecycle(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = $this->makeWorkflowWithVersion($tenant, $user);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflows/{$workflow->id}/trigger");
        $response->assertStatus(201);

        $runId = $response->json('data.id');
        $run = WorkflowRun::find($runId);

        $this->assertNotNull($run);
        $this->assertEquals($tenant->id, $run->tenant_id);

        $run->update([
            'status' => 'SUCCESS',
            'finished_at' => now(),
            'duration_ms' => 1500,
        ]);

        $detail = $this->actingAs($user, 'api')->getJson("/api/workflow-runs/{$runId}");
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.status', 'SUCCESS');
        $detail->assertJsonPath('data.duration_ms', 1500);

        $this->assertGreaterThanOrEqual(1, $run->stepRuns()->count());
        $this->assertGreaterThanOrEqual(1, $run->logs()->count());
    }

    public function test_failed_workflow_run_persists_error(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = $this->makeWorkflowWithVersion($tenant, $user);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflows/{$workflow->id}/trigger");
        $response->assertStatus(201);

        $runId = $response->json('data.id');
        $run = WorkflowRun::findOrFail($runId);

        WorkflowStepRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
            'attempt_count' => 2,
            'error_message' => 'Connection timeout to external service',
        ]);

        ExecutionLog::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_run_id' => $run->id,
            'level' => 'error',
            'event' => 'step.failed',
            'message' => 'Failed to connect to external service',
        ]);

        $run->update([
            'status' => 'FAILED',
            'finished_at' => now(),
            'duration_ms' => 5000,
        ]);

        $detail = $this->actingAs($user, 'api')->getJson("/api/workflow-runs/{$runId}");
        $detail->assertStatus(200);
        $detail->assertJsonPath('data.status', 'FAILED');
        $detail->assertJsonPath('data.duration_ms', 5000);

        $this->assertEquals(1, $run->stepRuns()->where('status', 'FAILED')->count());
    }

    public function test_cross_tenant_run_access_returns_404(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $owner = User::factory()->create(['tenant_id' => $tenant2->id, 'role' => 'admin']);
        $workflow = $this->makeWorkflowWithVersion($tenant2, $owner);

        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant2->id,
            'workflow_id' => $workflow->id,
            'workflow_version_id' => $workflow->current_version_id,
            'created_by' => $owner->id,
            'status' => 'PENDING',
        ]);

        $stranger = User::factory()->create(['tenant_id' => $tenant1->id, 'role' => 'admin']);

        $response = $this->actingAs($stranger, 'api')->getJson("/api/workflow-runs/{$run->id}");
        $response->assertStatus(404);
    }
}
