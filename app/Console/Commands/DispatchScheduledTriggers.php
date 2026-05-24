<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workflow\TriggerWorkflowAction;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Wakes up every minute (see Schedule::command in routes/console.php) and
 * dispatches scheduled workflow triggers whose next-run window has elapsed.
 *
 * The next_run_at column is the scheduling source of truth. When it's null
 * (e.g. on a freshly seeded trigger), we treat the trigger as immediately
 * due so the first tick after deploy will fire it. After dispatch, we use
 * dragonmantank/cron-expression (a Laravel runtime dependency) to compute
 * the next match relative to "now" so jobs don't drift.
 */
class DispatchScheduledTriggers extends Command
{
    protected $signature = 'flowforge:dispatch-scheduled-triggers';

    protected $description = 'Dispatch scheduled workflow triggers that are due.';

    public function handle(TriggerWorkflowAction $action): int
    {
        $now = now();

        // Pick up triggers that are either explicitly due (next_run_at <= now)
        // or never scheduled yet (next_run_at IS NULL — typical for freshly
        // seeded triggers). The eager load avoids an N+1 when we resolve the
        // workflow + creator below.
        $triggers = WorkflowTrigger::query()
            ->with(['workflow.currentVersion', 'creator'])
            ->where('type', 'scheduled')
            ->where('enabled', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        $dispatched = 0;

        foreach ($triggers as $trigger) {
            $workflow = $trigger->workflow;

            if (! $workflow || ! $workflow->currentVersion) {
                continue;
            }

            // Compute the *next* fire from this tick, not from last_run_at,
            // so a missed window doesn't snowball into a backlog.
            $next = $this->calculateNextRun($trigger->cron_expression, (string) ($trigger->timezone ?: 'UTC'), $now);

            DB::transaction(function () use ($trigger, $now, $next): void {
                $trigger->update([
                    'last_run_at' => $now,
                    'next_run_at' => $next,
                ]);
            });

            $actor = $trigger->creator ?? $workflow->creator;

            try {
                $action->execute($actor, $workflow, []);
                $dispatched++;
            } catch (Throwable $e) {
                // Log and continue so a single broken workflow doesn't stop
                // the rest of the schedule from firing this tick.
                $this->error(sprintf(
                    'Trigger %s for workflow %s failed: %s',
                    $trigger->id,
                    $workflow->id,
                    $e->getMessage(),
                ));
            }
        }

        $this->info("Dispatched {$dispatched} scheduled triggers.");

        return self::SUCCESS;
    }

    /**
     * Compute the next time `$cron` fires after `$reference` in the given
     * timezone. Falls back to a +1 minute scheduling when the expression is
     * malformed so the trigger remains live and we don't silently lose it.
     */
    private function calculateNextRun(?string $cron, string $timezone, \DateTimeInterface $reference): \DateTimeInterface
    {
        if (! is_string($cron) || trim($cron) === '') {
            return Carbon::instance($reference)->addMinute();
        }

        try {
            $expression = new CronExpression($cron);
            $next = $expression->getNextRunDate($reference, 0, false, $timezone);

            return Carbon::instance($next)->utc();
        } catch (Throwable) {
            return Carbon::instance($reference)->addMinute();
        }
    }
}
