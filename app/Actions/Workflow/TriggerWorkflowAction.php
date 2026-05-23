<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use App\Domain\Workflow\Services\WorkflowExecutor;
use App\Domain\Workflow\Services\WorkflowRunPersister;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TriggerWorkflowAction
{
    public function __construct(
        private readonly WorkflowExecutor $executor,
        private readonly WorkflowRunPersister $persister,
    ) {}

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

        $result = $this->executor->execute($version->definition);

        return $this->persister->persist($run, $version->definition, $result);
    }
}
