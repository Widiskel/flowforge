<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Actions\Workflow\CreateWorkflowAction;
use App\Actions\Workflow\DeleteWorkflowAction;
use App\Actions\Workflow\UpdateWorkflowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\StoreWorkflowRequest;
use App\Http\Requests\Workflow\UpdateWorkflowRequest;
use App\Http\Resources\Workflow\WorkflowResource;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Workflow::class);

        $query = Workflow::query()
            ->with('currentVersion')
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search')->value().'%');
        }

        $perPage = (int) $request->integer('per_page', 15);
        abort_if($perPage > 100, 422, 'per_page cannot be greater than 100.');

        return WorkflowResource::collection($query->paginate($perPage));
    }

    public function store(StoreWorkflowRequest $request, CreateWorkflowAction $action): WorkflowResource
    {
        $workflow = $action->execute($request->user(), $request->validated());

        return new WorkflowResource($workflow);
    }

    public function show(Request $request, string $workflow): WorkflowResource
    {
        $model = Workflow::query()
            ->with(['currentVersion', 'versions'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('view', $model);

        return new WorkflowResource($model);
    }

    public function update(UpdateWorkflowRequest $request, string $workflow, UpdateWorkflowAction $action): WorkflowResource
    {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $workflowModel = $action->execute($request->user(), $model, $request->validated());

        return new WorkflowResource($workflowModel);
    }

    public function destroy(Request $request, string $workflow, DeleteWorkflowAction $action): JsonResponse
    {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('delete', $model);

        $action->execute($model);

        return response()->json(status: 204);
    }
}
