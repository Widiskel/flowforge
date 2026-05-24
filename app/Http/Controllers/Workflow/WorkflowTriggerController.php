<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\StoreWorkflowTriggerRequest;
use App\Http\Resources\Workflow\WorkflowTriggerResource;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowTriggerController extends Controller
{
    public function index(Request $request, string $workflow)
    {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('view', $model);

        $perPage = (int) $request->integer('per_page', 15);

        validator(
            ['per_page' => $perPage],
            ['per_page' => ['integer', 'min:1', 'max:100']],
        )->validate();

        $triggers = WorkflowTrigger::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('workflow_id', $model->id)
            ->latest()
            ->paginate($perPage);

        return WorkflowTriggerResource::collection($triggers);
    }

    public function store(StoreWorkflowTriggerRequest $request, string $workflow): JsonResponse
    {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('update', $model);

        $trigger = WorkflowTrigger::query()->create([
            'tenant_id' => $model->tenant_id,
            'workflow_id' => $model->id,
            'type' => $request->input('type'),
            'webhook_secret' => $request->input('webhook_secret'),
            'cron_expression' => $request->input('cron_expression'),
            'timezone' => $request->input('timezone', 'UTC'),
            'enabled' => $request->input('enabled', true),
            'created_by' => $request->user()->id,
        ]);

        return (new WorkflowTriggerResource($trigger))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $workflow, string $trigger): JsonResponse
    {
        $model = Workflow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($workflow);

        $this->authorize('update', $model);

        $row = WorkflowTrigger::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('workflow_id', $model->id)
            ->findOrFail($trigger);

        $row->delete();

        return response()->json(status: 204);
    }
}
