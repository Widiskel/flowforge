<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GraphQL is exposed as a bonus read-only surface. These tests pin the
 * tenant-scoping invariant and the basic query shape so the bonus capability
 * doesn't regress without anyone noticing.
 */
class GraphQLEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo-'.Str::random(6)]);
        $this->admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',
        ]);

        $this->workflow = Workflow::create([
            'tenant_id' => $tenant->id,
            'name' => 'GraphQL fixture',
            'description' => 'Fixture workflow used by GraphQL endpoint tests.',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $version = WorkflowVersion::create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $this->workflow->id,
            'version_number' => 1,
            'definition' => $this->definition(),
            'source' => 'test',
            'change_summary' => 'Initial',
            'created_by' => $this->admin->id,
        ]);

        $this->workflow->update(['current_version_id' => $version->id]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/graphql', ['query' => '{ me { id } }']);

        $response->assertStatus(401);
    }

    public function test_me_query_returns_authenticated_user(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/graphql', [
            'query' => '{ me { id email role } }',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.me.email', $this->admin->email);
        $response->assertJsonPath('data.me.role', 'admin');
    }

    public function test_workflows_query_returns_tenant_scoped_list(): void
    {
        // A workflow in another tenant must not leak through the GraphQL surface.
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'admin']);
        Workflow::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other tenant workflow',
            'status' => 'active',
            'created_by' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->admin, 'api')->postJson('/api/graphql', [
            'query' => '{ workflows { id name } }',
        ]);

        $response->assertOk();
        $payload = $response->json('data.workflows');
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload);
        $this->assertSame($this->workflow->id, $payload[0]['id']);
    }

    public function test_workflow_query_by_id_returns_current_version(): void
    {
        $response = $this->actingAs($this->admin, 'api')->postJson('/api/graphql', [
            'query' => 'query($id: String!) { workflow(id: $id) { id name currentVersion { versionNumber steps { id type } } } }',
            'variables' => ['id' => $this->workflow->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.workflow.id', $this->workflow->id);
        $response->assertJsonPath('data.workflow.currentVersion.versionNumber', 1);
        $this->assertNotEmpty($response->json('data.workflow.currentVersion.steps'));
    }

    public function test_workflow_query_for_other_tenant_returns_null(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $stranger = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => 'admin']);

        $response = $this->actingAs($stranger, 'api')->postJson('/api/graphql', [
            'query' => 'query($id: String!) { workflow(id: $id) { id } }',
            'variables' => ['id' => $this->workflow->id],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('data.workflow'));
    }

    private function definition(): array
    {
        return [
            'schemaVersion' => 1,
            'name' => 'GraphQL fixture',
            'globalTimeoutMs' => 30000,
            'steps' => [
                [
                    'id' => 'noop',
                    'type' => 'SCRIPT',
                    'name' => 'No-op',
                    'dependsOn' => [],
                    'config' => ['script' => 'return null;'],
                    'retry' => ['maxAttempts' => 1],
                ],
            ],
        ];
    }
}
