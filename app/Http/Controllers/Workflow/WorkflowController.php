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

        $perPage = (int) $request->integer('per_page', 15);

        $sortAllowlist = ['created_at', 'updated_at', 'name', 'status'];
        $sortRaw = (string) $request->query('sort', '-updated_at');
        $sortDir = str_starts_with($sortRaw, '-') ? 'desc' : 'asc';
        $sortColumn = ltrim($sortRaw, '-');

        validator(
            ['per_page' => $perPage, 'sort_column' => $sortColumn],
            [
                'per_page' => ['integer', 'min:1', 'max:100'],
                'sort_column' => ['in:'.implode(',', $sortAllowlist)],
            ],
        )->validate();

        $query = Workflow::query()
            ->with('currentVersion')
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy($sortColumn, $sortDir);

        if ($request->filled('status')) {
            $allowed = ['draft', 'active', 'archived'];
            $status = (string) $request->string('status');
            if (! in_array($status, $allowed, true)) {
                abort(422, 'Unsupported status filter.');
            }
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = (string) $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        return WorkflowResource::collection($query->paginate($perPage));
    }

    public function store(StoreWorkflowRequest $request, CreateWorkflowAction $action): JsonResponse
    {
        $this->authorize('create', Workflow::class);

        $workflow = $action->execute($request->user(), $request->validated());

        return (new WorkflowResource($workflow))
            ->response()
            ->setStatusCode(201);
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

        $this->authorize('update', $model);

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
