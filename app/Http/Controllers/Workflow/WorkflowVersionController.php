<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Actions\Workflow\RollbackWorkflowVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\RollbackWorkflowRequest;
use App\Http\Resources\Workflow\WorkflowResource;
use App\Http\Resources\Workflow\WorkflowVersionResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Http\Request;

class WorkflowVersionController extends Controller
{
    public function index(Request $request, string $workflow)
    {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('view', $model);

        $perPage = (int) $request->integer('per_page', 15);
        abort_if($perPage > 100, 422, 'per_page cannot be greater than 100.');

        $versions = WorkflowVersion::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('workflow_id', $model->id)
            ->orderByDesc('version_number')
            ->paginate($perPage);

        return WorkflowVersionResource::collection($versions);
    }

    public function rollback(
        RollbackWorkflowRequest $request,
        string $workflow,
        string $version,
        RollbackWorkflowVersionAction $action,
    ): WorkflowResource {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $targetVersion = WorkflowVersion::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('workflow_id', $model->id)
            ->findOrFail($version);

        $workflowModel = $action->execute(
            $request->user(),
            $model,
            $targetVersion,
            $request->validated('change_summary'),
        );

        return new WorkflowResource($workflowModel);
    }
}
