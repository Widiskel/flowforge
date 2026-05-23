<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\ExecutionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Workflow $workflow;

    private WorkflowRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Monitor Tenant', 'slug' => 'monitor-tenant']);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin-monitor@test.local',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        $this->workflow = Workflow::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->admin->id,
            'name' => 'Monitor WF',
            'status' => 'active',
        ]);

        $this->run = WorkflowRun::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $this->workflow->id,
            'workflow_version_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'created_by' => $this->admin->id,
            'status' => 'SUCCESS',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 1000,
        ]);

        $this->actingAs($this->admin, 'api');
    }

    public function test_logs_endpoint_returns_run_logs(): void
    {
        ExecutionLog::create([
            'tenant_id' => $this->tenant->id,
            'workflow_run_id' => $this->run->id,
            'level' => 'info',
            'event' => 'run.started',
            'message' => 'Run started',
            'created_at' => now(),
        ]);

        ExecutionLog::create([
            'tenant_id' => $this->tenant->id,
            'workflow_run_id' => $this->run->id,
            'level' => 'info',
            'event' => 'run.completed',
            'message' => 'Run completed',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/workflow-runs/{$this->run->id}/logs");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.event', 'run.started');
    }

    public function test_logs_endpoint_returns_404_for_cross_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherWorkflow = Workflow::create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $this->admin->id,
            'name' => 'Other WF',
            'status' => 'active',
        ]);
        $otherRun = WorkflowRun::create([
            'tenant_id' => $otherTenant->id,
            'workflow_id' => $otherWorkflow->id,
            'workflow_version_id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'created_by' => $this->admin->id,
            'status' => 'SUCCESS',
        ]);

        $response = $this->getJson("/api/workflow-runs/{$otherRun->id}/logs");

        $response->assertStatus(404);
    }

    public function test_health_metrics_returns_last_24h_aggregates(): void
    {
        WorkflowRun::create([
            'tenant_id' => $this->tenant->id,
            'workflow_id' => $this->workflow->id,
            'workflow_version_id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'created_by' => $this->admin->id,
            'status' => 'FAILED',
            'duration_ms' => 2000,
        ]);

        $response = $this->getJson('/api/health/metrics?window=last_24h');

        $response->assertOk();
        $response->assertJsonPath('data.window', 'last_24h');
        $response->assertJsonPath('data.totals.runs', 2);
        $response->assertJsonPath('data.totals.success', 1);
        $response->assertJsonPath('data.totals.failed', 1);
    }

    public function test_health_metrics_is_tenant_scoped(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-2']);
        $otherWorkflow = Workflow::create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $this->admin->id,
            'name' => 'Other WF',
            'status' => 'active',
        ]);
        WorkflowRun::create([
            'tenant_id' => $otherTenant->id,
            'workflow_id' => $otherWorkflow->id,
            'workflow_version_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'created_by' => $this->admin->id,
            'status' => 'SUCCESS',
        ]);

        $response = $this->getJson('/api/health/metrics?window=last_24h');

        $response->assertOk();
        $response->assertJsonPath('data.totals.runs', 1);
    }

    public function test_logs_endpoint_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();

        $response = $this->getJson("/api/workflow-runs/{$this->run->id}/logs");

        $response->assertStatus(401);
    }
}
