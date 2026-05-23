<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Actions\Workflow\TriggerWorkflowAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Workflow\WorkflowRunResource;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowRunController extends Controller
{
    public function trigger(Request $request, string $workflow, TriggerWorkflowAction $action): JsonResponse
    {
        $model = Workflow::query()
            ->with('currentVersion')
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('rollback', $model); // editor/admin can trigger

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
        ]);

        $run = $action->execute($request->user(), $model, $validated['input'] ?? []);

        return (new WorkflowRunResource($run->load(['stepRuns', 'logs'])))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 15);

        validator(
            ['per_page' => $perPage],
            ['per_page' => ['integer', 'min:1', 'max:100']],
        )->validate();

        $query = WorkflowRun::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->latest();

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', $request->string('workflow_id')->value());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        return WorkflowRunResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, string $run): WorkflowRunResource
    {
        $model = WorkflowRun::query()
            ->with(['stepRuns', 'logs'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($run);

        return new WorkflowRunResource($model);
    }
}
