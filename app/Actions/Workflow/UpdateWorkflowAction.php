<?php

declare(strict_types=1);

namespace App\Actions\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

class UpdateWorkflowAction
{
    public function execute(User $actor, Workflow $workflow, array $payload): Workflow
    {
        return DB::transaction(function () use ($actor, $workflow, $payload): Workflow {
            $workflow->fill([
                'name' => $payload['name'] ?? $workflow->name,
                'description' => array_key_exists('description', $payload) ? $payload['description'] : $workflow->description,
                'status' => $payload['status'] ?? $workflow->status,
            ]);
            $workflow->save();

            if (array_key_exists('definition', $payload)) {
                $nextVersionNumber = (int) $workflow->versions()->max('version_number') + 1;

                $version = WorkflowVersion::query()->create([
                    'tenant_id' => $workflow->tenant_id,
                    'workflow_id' => $workflow->id,
                    'version_number' => $nextVersionNumber,
                    'definition' => $payload['definition'],
                    'source' => 'manual_update',
                    'change_summary' => $payload['change_summary'] ?? null,
                    'created_by' => $actor->id,
                ]);

                $workflow->forceFill([
                    'current_version_id' => $version->id,
                ])->save();
            }

            return $workflow->fresh(['currentVersion', 'versions']);
        });
    }
}
