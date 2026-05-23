<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiFailureAnalysis;
use App\Models\ExecutionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiFailureAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_422_for_non_failed_run(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'SUCCESS',
        ]);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Only failed or timed-out runs can be analyzed.');
    }

    public function test_returns_403_for_viewer_user(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'viewer']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
        ]);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $response->assertStatus(403);
    }

    public function test_returns_404_for_cross_tenant_access(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant1->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant2->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant2->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
        ]);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $response->assertStatus(404);
    }

    public function test_returns_cached_analysis_on_repeated_request(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
        ]);
        $stepRun = WorkflowStepRun::factory()->create([
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
            'error_message' => 'Connection timeout',
        ]);
        ExecutionLog::factory()->create([
            'workflow_run_id' => $run->id,
            'level' => 'error',
            'message' => 'Failed to connect to external service',
        ]);

        $first = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");
        $first->assertStatus(200);

        $second = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");
        $second->assertStatus(200);

        $firstId = $first->json('data.id');
        $secondId = $second->json('data.id');

        $this->assertEquals($firstId, $secondId);
        $this->assertEquals(1, AiFailureAnalysis::count());
    }

    public function test_generates_deterministic_mock_analysis_for_failed_run(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
        ]);
        $stepRun = WorkflowStepRun::factory()->create([
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
            'error_message' => 'Connection timeout',
        ]);
        ExecutionLog::factory()->create([
            'workflow_run_id' => $run->id,
            'level' => 'error',
            'message' => 'Failed to connect to external service',
        ]);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $response->assertStatus(200);
        $response->assertJsonPath('data.root_cause', 'Kegagalan paling mungkin terjadi di step '.$stepRun->step_id.': Failed to connect to external service');
        $response->assertJsonPath('data.suggested_fix', 'Periksa konfigurasi step, input tenant-scoped, dan dependency step sebelumnya. Ulangi run setelah error source diperbaiki.');
        $response->assertJsonPath('data.confidence', 'medium');
        $response->assertJsonPath('data.category', 'workflow_execution_failure');
        $this->assertIsArray($response->json('data.evidence'));
    }

    public function test_sanitizes_secrets_in_context(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
            'input' => [
                'api_key' => 'sk-123...cdef',
                'secret_token' => 'super-secret-value',
                'normal_field' => 'safe-value',
            ],
        ]);
        WorkflowStepRun::factory()->create([
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
            'error_message' => 'Error with api_key=sk-123...cdef',
        ]);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $response->assertStatus(200);
        $analysis = AiFailureAnalysis::first();
        $this->assertStringNotContainsString('sk-123...cdef', json_encode($analysis->evidence));
        $this->assertStringNotContainsString('super-secret-value', json_encode($analysis->evidence));
    }

    public function test_truncates_long_strings_in_context(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $longString = str_repeat('x', 500);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
            'input' => ['long_field' => $longString],
        ]);
        WorkflowStepRun::factory()->create([
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
            'error_message' => $longString,
        ]);

        $response = $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $response->assertStatus(200);
        $analysis = AiFailureAnalysis::first();
        $this->assertStringNotContainsString($longString, json_encode($analysis->evidence));
    }

    public function test_records_audit_log_on_analysis(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
        ]);
        WorkflowStepRun::factory()->create([
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
        ]);

        $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");

        $this->assertEquals(1, $run->aiAuditLogs()->count());
        $log = $run->aiAuditLogs->first();
        $this->assertEquals($tenant->id, $log->tenant_id);
        $this->assertEquals($user->id, $log->requested_by);
        $this->assertEquals('mock', $log->provider);
    }

    public function test_force_flag_bypasses_cache(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $workflow = Workflow::factory()->create(['tenant_id' => $tenant->id]);
        $run = WorkflowRun::factory()->create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'status' => 'FAILED',
        ]);
        WorkflowStepRun::factory()->create([
            'workflow_run_id' => $run->id,
            'status' => 'FAILED',
        ]);

        $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure");
        $this->actingAs($user, 'api')->postJson("/api/workflow-runs/{$run->id}/analyze-failure?force=1");

        $this->assertEquals(2, AiFailureAnalysis::count());
    }
}
