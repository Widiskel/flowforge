<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Workflow\Services\WorkflowExecutor;
use App\Domain\Workflow\Services\WorkflowRunPersister;
use App\Models\WorkflowRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Run a workflow's executor in a queue worker so the trigger HTTP request can
 * return immediately. Without this, the trigger handler holds the inbound PHP
 * request, the executor's HTTP steps loop back to the same dev server, and
 * the loopback request deadlocks behind the trigger that's still running.
 *
 * In production this is just good practice (long-running work doesn't belong
 * on the request thread). In development it's the only way the seeded demo
 * workflows that POST to /api/playground/* can succeed against `php artisan
 * serve` without per-worker forking quirks.
 */
class ExecuteWorkflowRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $runId,
    ) {}

    public function handle(WorkflowExecutor $executor, WorkflowRunPersister $persister): void
    {
        $run = WorkflowRun::query()
            ->with('version')
            ->find($this->runId);

        if (! $run || ! $run->version) {
            return;
        }

        try {
            $result = $executor->execute($run->version->definition);
            $persister->persist($run, $run->version->definition, $result);
        } catch (Throwable $e) {
            // Mark the run as failed with the executor exception so it shows
            // up in the UI even when something blows up below the handler
            // layer (DB outage, dispatcher misconfig, etc).
            $run->forceFill([
                'status' => 'FAILED',
                'finished_at' => now(),
            ])->save();

            throw $e;
        }
    }
}
