<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class RollbackWorkflowVersionAction
{
    public function execute(User $actor, Workflow $workflow, WorkflowVersion $targetVersion, ?string $changeSummary = null): Workflow
    {
        return DB::transaction(function () use ($actor, $workflow, $targetVersion, $changeSummary): Workflow {
            $nextVersionNumber = (int) $workflow->versions()->max('version_number') + 1;

            $version = WorkflowVersion::query()->create([
                'tenant_id' => $workflow->tenant_id,
                'workflow_id' => $workflow->id,
                'version_number' => $nextVersionNumber,
                'definition' => $targetVersion->definition,
                'source' => 'rollback',
                'change_summary' => $changeSummary ?? sprintf('Rollback to version %d.', $targetVersion->version_number),
                'rolled_back_from_version_id' => $targetVersion->id,
                'created_by' => $actor->id,
            ]);

            $workflow->update([
                'current_version_id' => $version->id,
            ]);

            return $workflow->fresh(['currentVersion', 'versions']);
        });
    }
}
