<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\WorkflowVersion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Wipe demo state and re-seed a small, reliable demo set:
     *  - 5 workflows that succeed every time, each following the
     *    canonical trigger → HTTP → SCRIPT → LOG pipeline.
     *  - 1 deterministic failure used to drive the AI failure-analysis demo.
     *
     * Trigger types are spread across manual / scheduled / webhook so the
     * demo dataset exercises all three flavors without bloating the list.
     */
    public function run(): void
    {
        $this->truncateDemoTables();

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

    private function truncateDemoTables(): void
    {
        $tables = [
            'execution_logs',
            'workflow_step_runs',
            'workflow_runs',
            'workflow_triggers',
            'ai_failure_analyses',
            'ai_audit_log',
            'workflow_versions',
            'workflows',
            'jwt_refresh_tokens',
            'users',
            'tenants',
            'playground_items',
        ];

        DB::transaction(function () use ($tables): void {
            if (Schema::hasTable('workflows')) {
                DB::table('workflows')->update(['current_version_id' => null]);
            }
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });
    }

    private function seedDemoWorkflows(string $tenantId, int|string $adminId): void
    {
        $playground = rtrim((string) config('app.url'), '/').'/api/playground';

        foreach ($this->workflowDefinitions($playground) as $data) {
            $workflow = Workflow::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'description' => $data['description'],
                'status' => $data['status'] ?? 'draft',
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

            WorkflowTrigger::create([
                'tenant_id' => $tenantId,
                'workflow_id' => $workflow->id,
                'type' => $data['trigger']['type'],
                'cron_expression' => $data['trigger']['cron_expression'] ?? null,
                'timezone' => $data['trigger']['timezone'] ?? 'UTC',
                'webhook_secret' => $data['trigger']['webhook_secret'] ?? null,
                'enabled' => $data['trigger']['enabled'] ?? true,
                'created_by' => $adminId,
            ]);
        }
    }

    /**
     * Five-plus-one demo set. Every successful workflow follows the same
     * canonical pipeline so the demo dataset reads consistently:
     *
     *   trigger → HTTP fetch → SCRIPT (transform) → LOG (write line)
     *
     * @return list<array{name:string,description:string,status?:string,definition:array,trigger:array}>
     */
    private function workflowDefinitions(string $playground): array
    {
        $stableHttp = [
            'maxAttempts' => 2,
            'backoff' => 'exponential',
            'initialDelayMs' => 500,
            'maxDelayMs' => 1500,
        ];

        return [
            $this->githubProbeWorkflow($stableHttp),
            $this->inventoryWorkflow($playground, $stableHttp),
            $this->onboardingWorkflow($playground, $stableHttp),
            $this->heartbeatWorkflow($playground, $stableHttp),
            $this->retryDemoWorkflow($playground),
            $this->failureShowcaseWorkflow($stableHttp),
        ];
    }

    /** 1 — Manual trigger, single external API → transform → log. */
    private function githubProbeWorkflow(array $stableHttp): array
    {
        return [
            'name' => 'GitHub status probe',
            'description' => 'Hit the public GitHub status API, transform the payload, and log the indicator.',
            'status' => 'active',
            'trigger' => ['type' => 'manual'],
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'GitHub status probe',
                'globalTimeoutMs' => 20000,
                'steps' => [
                    [
                        'id' => 'fetch_status',
                        'type' => 'HTTP',
                        'name' => 'Fetch GitHub status',
                        'dependsOn' => [],
                        'retry' => $stableHttp,
                        'config' => [
                            'method' => 'GET',
                            'url' => 'https://www.githubstatus.com/api/v2/status.json',
                            'headers' => ['Accept' => 'application/json'],
                            'timeoutMs' => 6000,
                        ],
                    ],
                    [
                        'id' => 'tag_payload',
                        'type' => 'SCRIPT',
                        'name' => 'Tag payload as monitored',
                        'dependsOn' => ['fetch_status'],
                        'config' => [
                            'script' => "const payload = \$doc.input.fetch_status?.json ?? {};\nreturn {\n    monitored: true,\n    indicator: payload.status?.indicator ?? 'unknown',\n    description: payload.status?.description ?? null,\n    captured_at: new Date().toISOString(),\n};\n",
                        ],
                    ],
                    [
                        'id' => 'log_outcome',
                        'type' => 'LOG',
                        'name' => 'Log outcome',
                        'dependsOn' => ['tag_payload'],
                        'config' => [
                            'level' => 'info',
                            'message' => 'GitHub status indicator={{ tag_payload.output.indicator }}',
                            'context' => [
                                'workflow' => 'github_status_probe',
                                'description' => '{{ tag_payload.output.description }}',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** 2 — DB-backed CRUD with playground/items. */
    private function inventoryWorkflow(string $playground, array $stableHttp): array
    {
        return [
            'name' => 'Inventory CRUD demo',
            'description' => 'Creates a row in playground_items, reads the aggregated snapshot, transforms it, and logs the result.',
            'status' => 'active',
            'trigger' => ['type' => 'manual'],
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'Inventory CRUD demo',
                'globalTimeoutMs' => 20000,
                'steps' => [
                    [
                        'id' => 'create_item',
                        'type' => 'HTTP',
                        'name' => 'Create demo item',
                        'dependsOn' => [],
                        'retry' => $stableHttp,
                        'config' => [
                            'method' => 'POST',
                            'url' => $playground.'/items',
                            'headers' => ['Content-Type' => 'application/json'],
                            'timeoutMs' => 5000,
                            'body' => [
                                'name' => 'Workflow demo widget',
                                'description' => 'Created by the Inventory CRUD demo workflow.',
                                'quantity' => 5,
                                'price_cents' => 1999,
                                'metadata' => ['source' => 'flowforge-demo', 'auto' => true],
                            ],
                        ],
                    ],
                    [
                        'id' => 'inventory_snapshot',
                        'type' => 'HTTP',
                        'name' => 'Aggregate inventory snapshot',
                        'dependsOn' => ['create_item'],
                        'retry' => $stableHttp,
                        'config' => [
                            'method' => 'GET',
                            'url' => $playground.'/inventory',
                            'headers' => ['Accept' => 'application/json'],
                            'timeoutMs' => 5000,
                        ],
                    ],
                    [
                        'id' => 'normalize_summary',
                        'type' => 'SCRIPT',
                        'name' => 'Normalize summary',
                        'dependsOn' => ['inventory_snapshot'],
                        'config' => [
                            'script' => "const inv = \$doc.input.inventory_snapshot?.json?.inventory ?? {};\nreturn {\n    total_items: inv.total_items ?? 0,\n    total_quantity: inv.total_quantity ?? 0,\n    reorder_required: inv.reorder_required ?? false,\n    captured_at: new Date().toISOString(),\n};\n",
                        ],
                    ],
                    [
                        'id' => 'log_summary',
                        'type' => 'LOG',
                        'name' => 'Log inventory summary',
                        'dependsOn' => ['normalize_summary'],
                        'config' => [
                            'level' => 'info',
                            'message' => 'Inventory items={{ normalize_summary.output.total_items }} qty={{ normalize_summary.output.total_quantity }} reorder={{ normalize_summary.output.reorder_required }}',
                            'context' => [
                                'workflow' => 'inventory_crud_demo',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** 3 — Webhook trigger, branch on plan, log result. */
    private function onboardingWorkflow(string $playground, array $stableHttp): array
    {
        return [
            'name' => 'User onboarding pipeline',
            'description' => 'Webhook-triggered: fetch profile, transform it, and log the welcome event.',
            'status' => 'active',
            'trigger' => ['type' => 'webhook', 'webhook_secret' => 'whsec_demo_'.Str::random(16)],
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'User onboarding pipeline',
                'globalTimeoutMs' => 20000,
                'steps' => [
                    [
                        'id' => 'fetch_user',
                        'type' => 'HTTP',
                        'name' => 'Fetch user profile',
                        'dependsOn' => [],
                        'retry' => $stableHttp,
                        'config' => [
                            'method' => 'GET',
                            'url' => $playground.'/users/42',
                            'headers' => ['Accept' => 'application/json'],
                            'timeoutMs' => 4000,
                        ],
                    ],
                    [
                        'id' => 'shape_welcome',
                        'type' => 'SCRIPT',
                        'name' => 'Shape welcome payload',
                        'dependsOn' => ['fetch_user'],
                        'config' => [
                            'script' => "const u = \$doc.input.fetch_user?.json?.user ?? {};\nreturn {\n    user_id: u.id ?? null,\n    name: u.name ?? 'unknown',\n    plan: u.plan ?? 'starter',\n    welcomed_at: new Date().toISOString(),\n};\n",
                        ],
                    ],
                    [
                        'id' => 'log_welcome',
                        'type' => 'LOG',
                        'name' => 'Log welcome event',
                        'dependsOn' => ['shape_welcome'],
                        'config' => [
                            'level' => 'info',
                            'message' => 'Welcomed user={{ shape_welcome.output.user_id }} plan={{ shape_welcome.output.plan }}',
                            'context' => [
                                'workflow' => 'user_onboarding_pipeline',
                                'name' => '{{ shape_welcome.output.name }}',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** 4 — Scheduled cron, drives the cron path through the local playground. */
    private function heartbeatWorkflow(string $playground, array $stableHttp): array
    {
        return [
            'name' => 'Heartbeat ping (every 10 minutes)',
            'description' => 'Scheduled cron — pings the playground every 10 minutes, transforms the response, then logs the heartbeat. The dispatcher command picks this up automatically when the scheduler is running.',
            'status' => 'active',
            'trigger' => ['type' => 'scheduled', 'cron_expression' => '*/10 * * * *', 'timezone' => 'UTC'],
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'Heartbeat ping',
                'globalTimeoutMs' => 15000,
                'steps' => [
                    [
                        'id' => 'ping',
                        'type' => 'HTTP',
                        'name' => 'Ping playground delay',
                        'dependsOn' => [],
                        'retry' => $stableHttp,
                        'config' => [
                            'method' => 'GET',
                            'url' => $playground.'/delay?ms=200',
                            'timeoutMs' => 4000,
                        ],
                    ],
                    [
                        'id' => 'tag_alive',
                        'type' => 'SCRIPT',
                        'name' => 'Tag heartbeat',
                        'dependsOn' => ['ping'],
                        'config' => [
                            'script' => "return {\n    alive: true,\n    ping_ms: \$doc.input.ping?.json?.delayed_ms ?? null,\n    at: new Date().toISOString(),\n};\n",
                        ],
                    ],
                    [
                        'id' => 'log_heartbeat',
                        'type' => 'LOG',
                        'name' => 'Log heartbeat',
                        'dependsOn' => ['tag_alive'],
                        'config' => [
                            'level' => 'info',
                            'message' => 'Heartbeat alive={{ tag_alive.output.alive }} ping_ms={{ tag_alive.output.ping_ms }}',
                            'context' => ['workflow' => 'heartbeat_ping'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** 5 — Flaky upstream demo. Drives retry + backoff in run logs. */
    private function retryDemoWorkflow(string $playground): array
    {
        return [
            'name' => 'Flaky upstream retry demo',
            'description' => 'Hits a 30%-failure endpoint with 4 attempts, transforms the response, and logs the outcome.',
            'status' => 'active',
            'trigger' => ['type' => 'manual'],
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'Flaky upstream retry demo',
                'globalTimeoutMs' => 25000,
                'steps' => [
                    [
                        'id' => 'maybe_fail',
                        'type' => 'HTTP',
                        'name' => 'Call flaky endpoint',
                        'dependsOn' => [],
                        'retry' => ['maxAttempts' => 4, 'backoff' => 'exponential', 'initialDelayMs' => 400, 'maxDelayMs' => 2000],
                        'config' => [
                            'method' => 'GET',
                            'url' => $playground.'/maybe-fail?fail_rate=0.3',
                            'timeoutMs' => 4000,
                        ],
                    ],
                    [
                        'id' => 'transform_payload',
                        'type' => 'SCRIPT',
                        'name' => 'Normalize response',
                        'dependsOn' => ['maybe_fail'],
                        'config' => [
                            'script' => "const upstream = \$doc.input.maybe_fail?.json ?? {};\nreturn {\n    outcome: upstream.outcome ?? 'unknown',\n    roll: upstream.roll ?? null,\n    archived_at: new Date().toISOString(),\n};\n",
                        ],
                    ],
                    [
                        'id' => 'log_outcome',
                        'type' => 'LOG',
                        'name' => 'Log retry outcome',
                        'dependsOn' => ['transform_payload'],
                        'config' => [
                            'level' => 'info',
                            'message' => 'Flaky upstream outcome={{ transform_payload.output.outcome }} roll={{ transform_payload.output.roll }}',
                            'context' => ['workflow' => 'flaky_upstream_retry_demo'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** 6 — Guaranteed failure for the AI failure-analysis demo. */
    private function failureShowcaseWorkflow(array $stableHttp): array
    {
        return [
            'name' => 'Failure analysis showcase',
            'description' => 'Designed to fail on the second step so the AI failure-analysis affordance has a reproducible target.',
            'status' => 'active',
            'trigger' => ['type' => 'manual'],
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'Failure analysis showcase',
                'globalTimeoutMs' => 15000,
                'steps' => [
                    [
                        'id' => 'fetch_resource',
                        'type' => 'HTTP',
                        'name' => 'Fetch a real resource (succeeds)',
                        'dependsOn' => [],
                        'retry' => $stableHttp,
                        'config' => [
                            'method' => 'GET',
                            'url' => 'https://jsonplaceholder.typicode.com/todos/1',
                            'headers' => ['Accept' => 'application/json'],
                            'timeoutMs' => 5000,
                        ],
                    ],
                    [
                        'id' => 'fail_demo',
                        'type' => 'SCRIPT',
                        'name' => 'Trigger demo failure',
                        'dependsOn' => ['fetch_resource'],
                        'config' => [
                            'script' => "throw new Error('Intentional failure for the AI failure-analysis demo.');\n",
                        ],
                    ],
                    [
                        'id' => 'log_unreachable',
                        'type' => 'LOG',
                        'name' => 'Log line (skipped after failure)',
                        'dependsOn' => ['fail_demo'],
                        'config' => [
                            'level' => 'info',
                            'message' => 'This line should never run because fail_demo aborts the workflow.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
