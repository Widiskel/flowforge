<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workflow\TriggerWorkflowAction;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchScheduledTriggers extends Command
{
    protected $signature = 'flowforge:dispatch-scheduled-triggers';

    protected $description = 'Dispatch scheduled workflow triggers that are due.';

    public function handle(TriggerWorkflowAction $action): int
    {
        $now = now();

        $triggers = WorkflowTrigger::query()
            ->where('type', 'scheduled')
            ->where('enabled', true)
            ->where('next_run_at', '<=', $now)
            ->get();

        $dispatched = 0;

        foreach ($triggers as $trigger) {
            $workflow = Workflow::query()
                ->with('currentVersion')
                ->where('id', $trigger->workflow_id)
                ->first();

            if (! $workflow || ! $workflow->currentVersion) {
                continue;
            }

            DB::transaction(function () use ($trigger, $now) {
                $trigger->update([
                    'last_run_at' => $now,
                    'next_run_at' => $this->calculateNextRun($trigger->cron_expression, $trigger->timezone),
                ]);
            });

            $action->execute($trigger->creator ?? $workflow->creator, $workflow, []);

            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} scheduled triggers.");

        return self::SUCCESS;
    }

    private function calculateNextRun(string $cron, string $timezone): \DateTimeInterface
    {
        // MVP: simple +1 minute. Production would use dragonmantank/cron-expression.
        return now($timezone)->addMinute();
    }
}
