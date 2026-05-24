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
     * Wipe seeded demo state from previous runs and re-seed a curated set of
     * 10 workflows. Nine reach SUCCESS against real public APIs (or the local
     * playground); the tenth is a guaranteed failure used to drive the AI
     * failure-analysis demo.
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

    /**
     * Reset the demo dataset so re-running the seeder produces a clean slate.
     * We only touch tables this seeder owns; user-defined data outside the
     * demo tenant survives.
     */
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
        ];

        DB::transaction(function () use ($tables): void {
            // Detach Workflow.current_version_id from WorkflowVersion before
            // truncating to avoid FK violations on Postgres / strict MySQL.
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

        $workflows = $this->workflowDefinitions($playground);

        foreach ($workflows as $data) {
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

            // Every workflow gets a default trigger so the builder loads it
            // pre-wired (n8n-style: a workflow is meaningless without an
            // entry-point). Trigger type is per-workflow on purpose so the
            // demo dataset exercises all three flavors.
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
     * @return list<array{name:string,description:string,status?:string,definition:array,trigger:array}>
     */
    private function workflowDefinitions(string $playground): array
    {
        return [
            // 1 — Manual trigger, single HTTP step against a public health API.
            [
                'name' => 'GitHub status probe',
                'description' => 'Hit the public GitHub status API and tag the response — fastest end-to-end demo.',
                'status' => 'active',
                'trigger' => ['type' => 'manual'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'GitHub status probe',
                    'globalTimeoutMs' => 30000,
                    'steps' => [
                        [
                            'id' => 'fetch_status',
                            'type' => 'HTTP',
                            'name' => 'Fetch GitHub status',
                            'dependsOn' => [],
                            'timeoutMs' => 8000,
                            'retry' => ['maxAttempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 500, 'maxDelayMs' => 4000],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://www.githubstatus.com/api/v2/status.json',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'tag_payload',
                            'type' => 'SCRIPT',
                            'name' => 'Tag payload as monitored',
                            'dependsOn' => ['fetch_status'],
                            'config' => ['operation' => 'transform'],
                        ],
                    ],
                ],
            ],

            // 2 — Scheduled trigger, pings the playground delay endpoint.
            [
                'name' => 'Heartbeat ping (every 5 minutes)',
                'description' => 'Periodically pings the playground to confirm the orchestrator is alive.',
                'status' => 'active',
                'trigger' => ['type' => 'scheduled', 'cron_expression' => '*/5 * * * *', 'timezone' => 'UTC'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Heartbeat ping',
                    'globalTimeoutMs' => 20000,
                    'steps' => [
                        [
                            'id' => 'ping',
                            'type' => 'HTTP',
                            'name' => 'Ping playground delay',
                            'dependsOn' => [],
                            'timeoutMs' => 5000,
                            'retry' => ['maxAttempts' => 2, 'backoff' => 'fixed', 'initialDelayMs' => 1000],
                            'config' => [
                                'method' => 'GET',
                                'url' => $playground.'/delay?ms=200',
                            ],
                        ],
                    ],
                ],
            ],

            // 3 — Webhook trigger, onboarding flow with multiple step types.
            [
                'name' => 'User onboarding pipeline',
                'description' => 'Fetch a user profile, provision a workspace, queue a welcome email, and cool down before reporting.',
                'status' => 'active',
                'trigger' => ['type' => 'webhook', 'webhook_secret' => 'whsec_demo_onboarding_'.Str::random(16)],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'User onboarding pipeline',
                    'globalTimeoutMs' => 60000,
                    'steps' => [
                        [
                            'id' => 'fetch_user',
                            'type' => 'HTTP',
                            'name' => 'Fetch user profile',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 500, 'maxDelayMs' => 5000],
                            'config' => [
                                'method' => 'GET',
                                'url' => $playground.'/users/42',
                            ],
                        ],
                        [
                            'id' => 'provision_workspace',
                            'type' => 'SCRIPT',
                            'name' => 'Provision workspace',
                            'dependsOn' => ['fetch_user'],
                            'config' => [
                                'operation' => 'set_output',
                                'output' => [
                                    'workspace_id' => 'ws_demo_42',
                                    'plan' => 'starter',
                                    'region' => 'ap-southeast-1',
                                ],
                            ],
                        ],
                        [
                            'id' => 'queue_welcome_email',
                            'type' => 'HTTP',
                            'name' => 'Queue welcome email',
                            'dependsOn' => ['provision_workspace'],
                            'retry' => ['maxAttempts' => 2, 'backoff' => 'exponential', 'initialDelayMs' => 750],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/notify',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => [
                                    'channel' => 'email',
                                    'message' => 'Welcome to FlowForge — your workspace is ready.',
                                ],
                            ],
                        ],
                        [
                            'id' => 'cool_down',
                            'type' => 'DELAY',
                            'name' => 'Cool down before reporting',
                            'dependsOn' => ['queue_welcome_email'],
                            'config' => ['durationMs' => 800],
                        ],
                    ],
                ],
            ],

            // 4 — Real public REST API: JSON Placeholder posts, exponential backoff baked in.
            [
                'name' => 'Sync latest blog post (JSONPlaceholder)',
                'description' => 'Pulls the newest post from JSONPlaceholder, normalizes it, then echoes a digest line to the playground.',
                'status' => 'active',
                'trigger' => ['type' => 'manual'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Sync latest blog post',
                    'globalTimeoutMs' => 45000,
                    'steps' => [
                        [
                            'id' => 'fetch_post',
                            'type' => 'HTTP',
                            'name' => 'Fetch post #1',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 500, 'maxDelayMs' => 4000],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://jsonplaceholder.typicode.com/posts/1',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'normalize',
                            'type' => 'SCRIPT',
                            'name' => 'Normalize payload',
                            'dependsOn' => ['fetch_post'],
                            'config' => ['operation' => 'transform'],
                        ],
                        [
                            'id' => 'archive_digest',
                            'type' => 'HTTP',
                            'name' => 'Archive digest line',
                            'dependsOn' => ['normalize'],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/echo',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => ['archived' => true, 'source' => 'jsonplaceholder'],
                            ],
                        ],
                    ],
                ],
            ],

            // 5 — Public IP lookup that branches on country code (CONDITION).
            [
                'name' => 'IP geolocation triage',
                'description' => 'Looks up our outbound IP via ipapi.co, branches on a non-empty country, and queues a slack-style notification.',
                'status' => 'active',
                'trigger' => ['type' => 'manual'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'IP geolocation triage',
                    'globalTimeoutMs' => 30000,
                    'steps' => [
                        [
                            'id' => 'lookup_ip',
                            'type' => 'HTTP',
                            'name' => 'Lookup public IP',
                            'dependsOn' => [],
                            'timeoutMs' => 8000,
                            'retry' => ['maxAttempts' => 2, 'backoff' => 'exponential', 'initialDelayMs' => 750],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://ipapi.co/json/',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'is_resolved',
                            'type' => 'CONDITION',
                            'name' => 'Is the lookup HTTP 200?',
                            'dependsOn' => ['lookup_ip'],
                            'config' => [
                                'field' => 'lookup_ip.status',
                                'operator' => 'equals',
                                'value' => 200,
                            ],
                        ],
                        [
                            'id' => 'announce',
                            'type' => 'HTTP',
                            'name' => 'Announce to ops channel',
                            'dependsOn' => ['is_resolved'],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/notify',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => [
                                    'channel' => 'slack',
                                    'message' => 'IP geolocation refreshed.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // 6 — Cat fact ingest. Public API with retry + transform + echo.
            [
                'name' => 'Daily cat fact archive',
                'description' => 'Fetches a fresh cat fact from catfact.ninja, normalizes it, then archives the line.',
                'status' => 'active',
                'trigger' => ['type' => 'scheduled', 'cron_expression' => '0 9 * * *', 'timezone' => 'UTC'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Daily cat fact archive',
                    'globalTimeoutMs' => 30000,
                    'steps' => [
                        [
                            'id' => 'fetch_fact',
                            'type' => 'HTTP',
                            'name' => 'Fetch cat fact',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 500, 'maxDelayMs' => 4000],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://catfact.ninja/fact',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'normalize_fact',
                            'type' => 'SCRIPT',
                            'name' => 'Normalize fact',
                            'dependsOn' => ['fetch_fact'],
                            'config' => ['operation' => 'transform'],
                        ],
                        [
                            'id' => 'archive',
                            'type' => 'HTTP',
                            'name' => 'Archive to playground',
                            'dependsOn' => ['normalize_fact'],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/echo',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => ['archived' => true, 'source' => 'catfact.ninja'],
                            ],
                        ],
                    ],
                ],
            ],

            // 7 — Flaky upstream demo with retry/backoff observable in logs.
            [
                'name' => 'Flaky upstream retry demo',
                'description' => 'Simulates a 30%-failure upstream so retry/backoff is observable in the run logs, then transforms the payload.',
                'status' => 'active',
                'trigger' => ['type' => 'manual'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Flaky upstream retry demo',
                    'globalTimeoutMs' => 60000,
                    'steps' => [
                        [
                            'id' => 'maybe_fail',
                            'type' => 'HTTP',
                            'name' => 'Call flaky endpoint',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 4, 'backoff' => 'exponential', 'initialDelayMs' => 500, 'maxDelayMs' => 6000],
                            'config' => [
                                'method' => 'GET',
                                'url' => $playground.'/maybe-fail?fail_rate=0.3',
                                'timeoutMs' => 5000,
                            ],
                        ],
                        [
                            'id' => 'transform_payload',
                            'type' => 'SCRIPT',
                            'name' => 'Normalize response',
                            'dependsOn' => ['maybe_fail'],
                            'config' => ['operation' => 'transform'],
                        ],
                        [
                            'id' => 'archive_log',
                            'type' => 'HTTP',
                            'name' => 'Archive trace',
                            'dependsOn' => ['transform_payload'],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/echo',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => ['archived' => true],
                            ],
                        ],
                    ],
                ],
            ],

            // 8 — Hourly metrics digest with branching + delay + republish.
            [
                'name' => 'Hourly platform metrics digest',
                'description' => 'Pulls platform metrics each hour, waits briefly, then republishes the digest if the snapshot is healthy.',
                'status' => 'active',
                'trigger' => ['type' => 'scheduled', 'cron_expression' => '0 * * * *', 'timezone' => 'UTC'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Hourly platform metrics digest',
                    'globalTimeoutMs' => 60000,
                    'steps' => [
                        [
                            'id' => 'pull_metrics',
                            'type' => 'HTTP',
                            'name' => 'Pull metrics snapshot',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 1000, 'maxDelayMs' => 8000],
                            'config' => [
                                'method' => 'GET',
                                'url' => $playground.'/metrics',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'is_healthy',
                            'type' => 'CONDITION',
                            'name' => 'Is platform healthy?',
                            'dependsOn' => ['pull_metrics'],
                            'config' => [
                                'field' => 'pull_metrics.status',
                                'operator' => 'equals',
                                'value' => 200,
                            ],
                        ],
                        [
                            'id' => 'cool_down',
                            'type' => 'DELAY',
                            'name' => 'Wait before publish',
                            'dependsOn' => ['is_healthy'],
                            'config' => ['durationMs' => 500],
                        ],
                        [
                            'id' => 'publish_digest',
                            'type' => 'HTTP',
                            'name' => 'Publish digest webhook',
                            'dependsOn' => ['cool_down'],
                            'retry' => ['maxAttempts' => 2, 'backoff' => 'fixed', 'initialDelayMs' => 1000],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/echo',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => ['digest' => 'metrics ready'],
                            ],
                        ],
                    ],
                ],
            ],

            // 9 — Public dog API with parallel root steps, then a join + echo.
            [
                'name' => 'Animal-of-the-day fan-out',
                'description' => 'Fetches a random dog and a cat fact in parallel, normalizes both, then archives a single combined line.',
                'status' => 'active',
                'trigger' => ['type' => 'manual'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Animal-of-the-day fan-out',
                    'globalTimeoutMs' => 45000,
                    'steps' => [
                        [
                            'id' => 'fetch_dog',
                            'type' => 'HTTP',
                            'name' => 'Fetch random dog photo',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 2, 'backoff' => 'exponential', 'initialDelayMs' => 500],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://dog.ceo/api/breeds/image/random',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'fetch_cat',
                            'type' => 'HTTP',
                            'name' => 'Fetch random cat fact',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 2, 'backoff' => 'exponential', 'initialDelayMs' => 500],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://catfact.ninja/fact',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'normalize_join',
                            'type' => 'SCRIPT',
                            'name' => 'Join + normalize payloads',
                            'dependsOn' => ['fetch_dog', 'fetch_cat'],
                            'config' => ['operation' => 'transform'],
                        ],
                        [
                            'id' => 'archive_combined',
                            'type' => 'HTTP',
                            'name' => 'Archive combined line',
                            'dependsOn' => ['normalize_join'],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/echo',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => ['archived' => true, 'source' => 'animal-of-the-day'],
                            ],
                        ],
                    ],
                ],
            ],

            // 10 — JSONPlaceholder users list with conditional + delay before publish.
            [
                'name' => 'User directory snapshot',
                'description' => 'Fetches the JSONPlaceholder users list, asserts the response is healthy, waits a beat, then archives the snapshot.',
                'status' => 'active',
                'trigger' => ['type' => 'scheduled', 'cron_expression' => '*/30 * * * *', 'timezone' => 'UTC'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'User directory snapshot',
                    'globalTimeoutMs' => 45000,
                    'steps' => [
                        [
                            'id' => 'fetch_users',
                            'type' => 'HTTP',
                            'name' => 'Fetch users list',
                            'dependsOn' => [],
                            'retry' => ['maxAttempts' => 3, 'backoff' => 'exponential', 'initialDelayMs' => 750, 'maxDelayMs' => 6000],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://jsonplaceholder.typicode.com/users',
                                'headers' => ['Accept' => 'application/json'],
                                'timeoutMs' => 8000,
                            ],
                        ],
                        [
                            'id' => 'is_ok',
                            'type' => 'CONDITION',
                            'name' => 'Did the upstream return 200?',
                            'dependsOn' => ['fetch_users'],
                            'config' => [
                                'field' => 'fetch_users.status',
                                'operator' => 'equals',
                                'value' => 200,
                            ],
                        ],
                        [
                            'id' => 'cool_down',
                            'type' => 'DELAY',
                            'name' => 'Cool down before archive',
                            'dependsOn' => ['is_ok'],
                            'config' => ['durationMs' => 600],
                        ],
                        [
                            'id' => 'archive_snapshot',
                            'type' => 'HTTP',
                            'name' => 'Archive snapshot',
                            'dependsOn' => ['cool_down'],
                            'config' => [
                                'method' => 'POST',
                                'url' => $playground.'/echo',
                                'headers' => ['Content-Type' => 'application/json'],
                                'body' => ['archived' => true, 'source' => 'jsonplaceholder.users'],
                            ],
                        ],
                    ],
                ],
            ],

            // 11 — Guaranteed failure for AI failure-analysis demo.
            [
                'name' => 'Failure analysis showcase',
                'description' => 'Designed to fail on the second step so the AI failure-analysis affordance has a reproducible target run.',
                'status' => 'active',
                'trigger' => ['type' => 'manual'],
                'definition' => [
                    'schemaVersion' => 1,
                    'name' => 'Failure analysis showcase',
                    'globalTimeoutMs' => 30000,
                    'steps' => [
                        [
                            'id' => 'fetch_resource',
                            'type' => 'HTTP',
                            'name' => 'Fetch a real resource (succeeds)',
                            'dependsOn' => [],
                            'config' => [
                                'method' => 'GET',
                                'url' => 'https://jsonplaceholder.typicode.com/todos/1',
                                'headers' => ['Accept' => 'application/json'],
                            ],
                        ],
                        [
                            'id' => 'fail_demo',
                            'type' => 'SCRIPT',
                            'name' => 'Trigger demo failure',
                            'dependsOn' => ['fetch_resource'],
                            'config' => ['operation' => 'fail_demo'],
                        ],
                        [
                            'id' => 'should_not_run',
                            'type' => 'SCRIPT',
                            'name' => 'Downstream cleanup (skipped)',
                            'dependsOn' => ['fail_demo'],
                            'config' => ['operation' => 'noop'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
