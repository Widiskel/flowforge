<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pin the recurring scheduler so a single dispatch tick can't silently
 * regress into a one-shot. Two invariants matter:
 *
 *   1. Triggers with NULL `next_run_at` are picked up immediately and have
 *      their next-run computed from the cron expression after dispatch.
 *   2. After dispatch, the recomputed `next_run_at` actually matches the
 *      cron expression — so a `*&#47;10 * * * *` trigger lands on the next
 *      10-minute boundary, not 1 minute later.
 */
class DispatchScheduledTriggersTest extends TestCase
{
    use RefreshDatabase;

    public function test_picks_up_triggers_with_null_next_run_and_dispatches_them(): void
    {
        $now = Carbon::parse('2026-05-24 09:03:00', 'UTC');
        Carbon::setTestNow($now);

        $trigger = $this->makeScheduledTrigger('*/10 * * * *', null);

        $exitCode = $this->artisan('flowforge:dispatch-scheduled-triggers')
            ->expectsOutputToContain('Dispatched 1 scheduled triggers.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(0, $exitCode);

        $trigger->refresh();
        $this->assertNotNull($trigger->next_run_at);
        $this->assertNotNull($trigger->last_run_at);
        // 09:03 → next */10 boundary is 09:10 UTC
        $this->assertSame('2026-05-24 09:10:00', $trigger->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_skips_triggers_that_are_not_yet_due(): void
    {
        $now = Carbon::parse('2026-05-24 09:03:00', 'UTC');
        Carbon::setTestNow($now);

        $future = Carbon::parse('2026-05-24 09:10:00', 'UTC');
        $this->makeScheduledTrigger('*/10 * * * *', $future);

        $this->artisan('flowforge:dispatch-scheduled-triggers')
            ->expectsOutputToContain('Dispatched 0 scheduled triggers.')
            ->assertSuccessful();
    }

    public function test_recomputes_next_run_using_the_cron_expression_not_a_fixed_offset(): void
    {
        $now = Carbon::parse('2026-05-24 09:03:00', 'UTC');
        Carbon::setTestNow($now);

        // next_run_at is in the past so it should fire immediately.
        $trigger = $this->makeScheduledTrigger('*/10 * * * *', Carbon::parse('2026-05-24 09:00:00', 'UTC'));

        $this->artisan('flowforge:dispatch-scheduled-triggers')->assertSuccessful();

        $trigger->refresh();
        // After dispatch, next is the next 10-minute boundary (09:10), not
        // last_run_at + 1 minute (the old buggy behavior).
        $this->assertSame('2026-05-24 09:10:00', $trigger->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_disabled_triggers_are_ignored(): void
    {
        $now = Carbon::parse('2026-05-24 09:03:00', 'UTC');
        Carbon::setTestNow($now);

        $trigger = $this->makeScheduledTrigger('*/10 * * * *', null);
        $trigger->update(['enabled' => false]);

        $this->artisan('flowforge:dispatch-scheduled-triggers')
            ->expectsOutputToContain('Dispatched 0 scheduled triggers.')
            ->assertSuccessful();

        $trigger->refresh();
        $this->assertNull($trigger->last_run_at);
        $this->assertNull($trigger->next_run_at);
    }

    private function makeScheduledTrigger(string $cron, ?Carbon $nextRunAt): WorkflowTrigger
    {
        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo-'.Str::random(6)]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        $workflow = Workflow::create([
            'tenant_id' => $tenant->id,
            'name' => 'Scheduled fixture',
            'description' => null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $version = WorkflowVersion::create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'version_number' => 1,
            'definition' => [
                'schemaVersion' => 1,
                'name' => 'Scheduled fixture',
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
            ],
            'source' => 'test',
            'change_summary' => 'Initial',
            'created_by' => $user->id,
        ]);

        $workflow->update(['current_version_id' => $version->id]);

        return WorkflowTrigger::create([
            'tenant_id' => $tenant->id,
            'workflow_id' => $workflow->id,
            'type' => 'scheduled',
            'cron_expression' => $cron,
            'timezone' => 'UTC',
            'enabled' => true,
            'next_run_at' => $nextRunAt,
            'created_by' => $user->id,
        ]);
    }
}
