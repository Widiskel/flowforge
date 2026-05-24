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

        $this->authorize('trigger', $model);

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

        $sortAllowlist = ['created_at', 'started_at', 'finished_at', 'duration_ms', 'status'];
        $sortRaw = (string) $request->query('sort', '-created_at');
        $sortDir = str_starts_with($sortRaw, '-') ? 'desc' : 'asc';
        $sortColumn = ltrim($sortRaw, '-');

        validator(
            ['per_page' => $perPage, 'sort_column' => $sortColumn],
            [
                'per_page' => ['integer', 'min:1', 'max:100'],
                'sort_column' => ['in:'.implode(',', $sortAllowlist)],
            ],
        )->validate();

        $query = WorkflowRun::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy($sortColumn, $sortDir);

        if ($request->filled('workflow_id')) {
            $query->where('workflow_id', (string) $request->string('workflow_id'));
        }

        if ($request->filled('status')) {
            $allowed = ['PENDING', 'RUNNING', 'SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED'];
            $status = mb_strtoupper((string) $request->string('status'));
            if (! in_array($status, $allowed, true)) {
                abort(422, 'Unsupported run status filter.');
            }
            $query->where('status', $status);
        }

        if ($request->filled('from')) {
            $from = $this->parseTimestamp((string) $request->string('from'));
            $query->where('created_at', '>=', $from);
        }

        if ($request->filled('to')) {
            $to = $this->parseTimestamp((string) $request->string('to'));
            $query->where('created_at', '<=', $to);
        }

        return WorkflowRunResource::collection($query->paginate($perPage));
    }

    private function parseTimestamp(string $raw): \DateTimeInterface
    {
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            abort(422, 'Invalid timestamp format. Use ISO-8601 (e.g. 2026-05-24T00:00:00Z).');
        }
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
