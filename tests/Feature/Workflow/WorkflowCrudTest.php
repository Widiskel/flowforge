<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $editor;

    private User $viewer;

    private array $validDefinition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        $this->editor = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Editor',
            'email' => 'editor@test.local',
            'role' => 'editor',
            'password' => bcrypt('password'),
        ]);

        $this->viewer = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer@test.local',
            'role' => 'viewer',
            'password' => bcrypt('password'),
        ]);

        $this->validDefinition = [
            'schemaVersion' => 1,
            'name' => 'Test Workflow',
            'globalTimeoutMs' => 60000,
            'steps' => [
                [
                    'id' => 'step_1',
                    'type' => 'HTTP',
                    'name' => 'Fetch data',
                    'dependsOn' => [],
                    'timeoutMs' => 5000,
                    'config' => ['method' => 'GET', 'url' => 'https://example.test/api'],
                ],
            ],
        ];
    }

    private function actingAsJwt(User $user): self
    {
        $this->actingAs($user, 'api');

        return $this;
    }

    // --- CREATE ---

    public function test_admin_can_create_workflow(): void
    {
        $this->actingAsJwt($this->admin);

        $response = $this->postJson('/api/workflows', [
            'name' => 'My Workflow',
            'description' => 'A test workflow',
            'definition' => $this->validDefinition,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'My Workflow');
        $response->assertJsonPath('data.tenant_id', $this->tenant->id);
        $response->assertJsonPath('data.current_version.version_number', 1);
        $response->assertJsonPath('data.current_version.source', 'manual_update');

        $this->assertDatabaseHas('workflows', ['name' => 'My Workflow', 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('workflow_versions', ['workflow_id' => $response->json('data.id'), 'version_number' => 1]);
    }

    public function test_editor_can_create_workflow(): void
    {
        $this->actingAsJwt($this->editor);

        $response = $this->postJson('/api/workflows', [
            'name' => 'Editor Workflow',
            'definition' => $this->validDefinition,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Editor Workflow');
    }

    public function test_viewer_cannot_create_workflow(): void
    {
        $this->actingAsJwt($this->viewer);

        $response = $this->postJson('/api/workflows', [
            'name' => 'Viewer Workflow',
            'definition' => $this->validDefinition,
        ]);

        $response->assertStatus(403);
    }

    public function test_create_workflow_validates_definition(): void
    {
        $this->actingAsJwt($this->admin);

        $response = $this->postJson('/api/workflows', [
            'name' => 'Bad Workflow',
            'definition' => ['schemaVersion' => 1, 'name' => 'x'],
        ]);

        $response->assertStatus(422);
    }

    public function test_create_workflow_rejects_dag_with_cycle(): void
    {
        $this->actingAsJwt($this->admin);

        $cyclic = $this->validDefinition;
        $cyclic['steps'] = [
            ['id' => 'a', 'type' => 'SCRIPT', 'name' => 'A', 'dependsOn' => ['b'], 'config' => ['script' => 'return null;']],
            ['id' => 'b', 'type' => 'SCRIPT', 'name' => 'B', 'dependsOn' => ['a'], 'config' => ['script' => 'return null;']],
        ];

        $response = $this->postJson('/api/workflows', [
            'name' => 'Cyclic Workflow',
            'definition' => $cyclic,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['definition']);
    }

    // --- LIST ---

    public function test_list_workflows_returns_tenant_scoped(): void
    {
        $this->actingAsJwt($this->admin);

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        Workflow::create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $this->admin->id,
            'name' => 'Other Tenant WF',
            'status' => 'draft',
        ]);

        $this->postJson('/api/workflows', [
            'name' => 'My WF',
            'definition' => $this->validDefinition,
        ]);

        $response = $this->getJson('/api/workflows');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'My WF');
    }

    public function test_list_workflows_supports_search(): void
    {
        $this->actingAsJwt($this->admin);

        $this->postJson('/api/workflows', [
            'name' => 'Incident Notifier',
            'definition' => $this->validDefinition,
        ]);
        $this->postJson('/api/workflows', [
            'name' => 'Deploy Pipeline',
            'definition' => $this->validDefinition,
        ]);

        $response = $this->getJson('/api/workflows?search=Incident');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Incident Notifier');
    }

    public function test_list_rejects_per_page_over_100(): void
    {
        $this->actingAsJwt($this->admin);

        $response = $this->getJson('/api/workflows?per_page=101');

        $response->assertStatus(422);
    }

    // --- SHOW ---

    public function test_show_workflow_returns_current_version(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Show WF',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $response = $this->getJson("/api/workflows/{$id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Show WF');
        $response->assertJsonPath('data.current_version.version_number', 1);
    }

    public function test_show_workflow_cross_tenant_returns_404(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other2']);
        $workflow = Workflow::create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $this->admin->id,
            'name' => 'Cross Tenant WF',
            'status' => 'draft',
        ]);

        $this->actingAsJwt($this->admin);

        $response = $this->getJson("/api/workflows/{$workflow->id}");

        $response->assertStatus(404);
    }

    // --- UPDATE ---

    public function test_update_metadata_does_not_create_new_version(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Original',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $response = $this->putJson("/api/workflows/{$id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated Name');
        $response->assertJsonPath('data.current_version.version_number', 1);
    }

    public function test_update_definition_creates_new_version(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Versioned WF',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $newDefinition = $this->validDefinition;
        $newDefinition['name'] = 'Updated Definition';

        $response = $this->putJson("/api/workflows/{$id}", [
            'definition' => $newDefinition,
            'change_summary' => 'Updated step config',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.current_version.version_number', 2);
        $response->assertJsonPath('data.current_version.change_summary', 'Updated step config');

        $this->assertDatabaseCount('workflow_versions', 2);
    }

    public function test_viewer_cannot_update_workflow(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Admin WF',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $this->actingAsJwt($this->viewer);

        $response = $this->putJson("/api/workflows/{$id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    // --- DELETE ---

    public function test_admin_can_delete_workflow(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'To Delete',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $response = $this->deleteJson("/api/workflows/{$id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('workflows', ['id' => $id]);
    }

    public function test_editor_cannot_delete_workflow(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Editor No Delete',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $this->actingAsJwt($this->editor);

        $response = $this->deleteJson("/api/workflows/{$id}");

        $response->assertStatus(403);
    }

    // --- VERSIONS ---

    public function test_list_versions_returns_all_versions(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Multi Version',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');

        $newDef = $this->validDefinition;
        $newDef['name'] = 'V2';
        $this->putJson("/api/workflows/{$id}", ['definition' => $newDef]);

        $response = $this->getJson("/api/workflows/{$id}/versions");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.version_number', 2);
        $response->assertJsonPath('data.1.version_number', 1);
    }

    // --- ROLLBACK ---

    public function test_rollback_creates_new_version_from_old(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Rollback WF',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');
        $v1Id = $createResponse->json('data.current_version.id');

        $newDef = $this->validDefinition;
        $newDef['name'] = 'V2 Definition';
        $this->putJson("/api/workflows/{$id}", ['definition' => $newDef]);

        $response = $this->postJson("/api/workflows/{$id}/rollback/{$v1Id}");

        $response->assertOk();
        $response->assertJsonPath('data.current_version.version_number', 3);
        $response->assertJsonPath('data.current_version.source', 'rollback');
        $response->assertJsonPath('data.current_version.rolled_back_from_version_id', $v1Id);

        // Original v1 definition preserved
        $v3Def = $response->json('data.current_version.definition');
        $this->assertEquals($this->validDefinition, $v3Def);
    }

    public function test_rollback_does_not_mutate_old_version(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Immutable Check',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');
        $v1Id = $createResponse->json('data.current_version.id');

        $newDef = $this->validDefinition;
        $newDef['name'] = 'V2';
        $this->putJson("/api/workflows/{$id}", ['definition' => $newDef]);

        $this->postJson("/api/workflows/{$id}/rollback/{$v1Id}");

        $v1 = WorkflowVersion::find($v1Id);
        $this->assertEquals(1, $v1->version_number);
        $this->assertEquals($this->validDefinition, $v1->definition);
    }

    public function test_viewer_cannot_rollback(): void
    {
        $this->actingAsJwt($this->admin);

        $createResponse = $this->postJson('/api/workflows', [
            'name' => 'Viewer Rollback',
            'definition' => $this->validDefinition,
        ]);

        $id = $createResponse->json('data.id');
        $v1Id = $createResponse->json('data.current_version.id');

        $this->actingAsJwt($this->viewer);

        $response = $this->postJson("/api/workflows/{$id}/rollback/{$v1Id}");

        $response->assertStatus(403);
    }
}
