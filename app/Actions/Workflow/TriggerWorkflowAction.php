<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use App\Jobs\ExecuteWorkflowRun;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TriggerWorkflowAction
{
    /**
     * Create a `PENDING` run row, dispatch the executor as a queued job, and
     * return the run record. Sync queue connection (used in tests) runs the
     * job inline so the returned record already reflects terminal state;
     * database/redis queue connections (production + dev) hand off to a
     * worker so the trigger HTTP request returns fast and the executor's
     * loopback HTTP steps don't deadlock the dev server.
     */
    public function execute(User $actor, Workflow $workflow, array $input = []): WorkflowRun
    {
        $version = $workflow->currentVersion;

        if (! $version) {
            throw new RuntimeException('Workflow has no current version.');
        }

        $run = DB::transaction(function () use ($actor, $workflow, $version, $input): WorkflowRun {
            return WorkflowRun::query()->create([
                'tenant_id' => $workflow->tenant_id,
                'workflow_id' => $workflow->id,
                'workflow_version_id' => $version->id,
                'created_by' => $actor->id,
                'status' => 'PENDING',
                'input' => $input,
                'timeout_ms' => $version->definition['globalTimeoutMs'] ?? null,
            ]);
        });

        ExecuteWorkflowRun::dispatch($run->id);

        return $run->refresh();
    }
}
