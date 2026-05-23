<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class CreateWorkflowAction
{
    public function execute(User $actor, array $payload): Workflow
    {
        return DB::transaction(function () use ($actor, $payload): Workflow {
            $workflow = Workflow::query()->create([
                'tenant_id' => $actor->tenant_id,
                'created_by' => $actor->id,
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'status' => $payload['status'] ?? 'draft',
            ]);

            $version = WorkflowVersion::query()->create([
                'tenant_id' => $actor->tenant_id,
                'workflow_id' => $workflow->id,
                'version_number' => 1,
                'definition' => $payload['definition'],
                'source' => 'manual_update',
                'change_summary' => $payload['change_summary'] ?? 'Initial workflow version.',
                'created_by' => $actor->id,
            ]);

            $workflow->update([
                'current_version_id' => $version->id,
            ]);

            return $workflow->fresh(['currentVersion', 'versions']);
        });
    }
}
