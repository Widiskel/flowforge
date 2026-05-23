<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'FlowForge Demo Tenant',
            'slug' => 'flowforge-demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accounts = [
            ['name' => 'Demo Admin', 'email' => 'admin@flowforge.test', 'role' => 'admin'],
            ['name' => 'Demo Editor', 'email' => 'editor@flowforge.test', 'role' => 'editor'],
            ['name' => 'Demo Viewer', 'email' => 'viewer@flowforge.test', 'role' => 'viewer'],
        ];

        $adminId = null;

        foreach ($accounts as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'tenant_id' => $tenantId,
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            if ($account['role'] === 'admin') {
                $adminId = $user->id;
            }
        }

        $this->seedDemoWorkflows($tenantId, $adminId);
    }

    private function seedDemoWorkflows(string $tenantId, int|string $adminId): void
    {
        $workflows = [
            [
                'name' => 'Incident Notifier',
                'description' => 'Detect incident, classify severity, notify on-call team via Slack and PagerDuty.',
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Incident Notifier',
                    'globalTimeoutMs' => 60000,
                    'steps' => [
                        ['id' => 'detect', 'type' => 'HTTP', 'name' => 'Detect Incident', 'dependsOn' => [], 'config' => []],
                        ['id' => 'classify', 'type' => 'SCRIPT', 'name' => 'Classify Severity', 'dependsOn' => ['detect'], 'config' => ['operation' => 'noop']],
                        ['id' => 'notify_slack', 'type' => 'HTTP', 'name' => 'Notify Slack', 'dependsOn' => ['classify'], 'config' => []],
                        ['id' => 'notify_pager', 'type' => 'HTTP', 'name' => 'Notify PagerDuty', 'dependsOn' => ['classify'], 'config' => []],
                        ['id' => 'log_incident', 'type' => 'SCRIPT', 'name' => 'Log to DB', 'dependsOn' => ['notify_slack', 'notify_pager'], 'config' => ['operation' => 'noop']],
                    ],
                ],
            ],
            [
                'name' => 'Data Pipeline ETL',
                'description' => 'Extract CSV, validate schema, transform data, load to warehouse.',
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Data Pipeline ETL',
                    'globalTimeoutMs' => 120000,
                    'steps' => [
                        ['id' => 'extract', 'type' => 'HTTP', 'name' => 'Extract CSV', 'dependsOn' => [], 'config' => []],
                        ['id' => 'validate', 'type' => 'SCRIPT', 'name' => 'Validate Schema', 'dependsOn' => ['extract'], 'config' => ['operation' => 'noop']],
                        ['id' => 'transform', 'type' => 'SCRIPT', 'name' => 'Transform Data', 'dependsOn' => ['validate'], 'config' => ['operation' => 'transform']],
                        ['id' => 'load', 'type' => 'HTTP', 'name' => 'Load to Warehouse', 'dependsOn' => ['transform'], 'config' => []],
                    ],
                ],
            ],
            [
                'name' => 'User Onboarding',
                'description' => 'Welcome email, provision workspace, assign role, notify admin.',
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'User Onboarding',
                    'globalTimeoutMs' => 30000,
                    'steps' => [
                        ['id' => 'welcome', 'type' => 'HTTP', 'name' => 'Send Welcome Email', 'dependsOn' => [], 'config' => []],
                        ['id' => 'provision', 'type' => 'SCRIPT', 'name' => 'Provision Workspace', 'dependsOn' => ['welcome'], 'config' => ['operation' => 'set_output', 'output' => ['workspace_id' => 'ws-demo-001']]],
                        ['id' => 'assign_role', 'type' => 'SCRIPT', 'name' => 'Assign Default Role', 'dependsOn' => ['provision'], 'config' => ['operation' => 'noop']],
                        ['id' => 'notify_admin', 'type' => 'HTTP', 'name' => 'Notify Admin', 'dependsOn' => ['assign_role'], 'config' => []],
                    ],
                ],
            ],
        ];

        foreach ($workflows as $data) {
            $workflow = Workflow::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'description' => $data['description'],
                'status' => 'active',
                'created_by' => $adminId,
            ]);

            $version = WorkflowVersion::create([
                'tenant_id' => $tenantId,
                'workflow_id' => $workflow->id,
                'version_number' => 1,
                'definition' => $data['definition'],
                'source' => 'seeder',
                'change_summary' => 'Initial version from demo seeder.',
                'created_by' => $adminId,
            ]);

            $workflow->update(['current_version_id' => $version->id]);
        }
    }
}
