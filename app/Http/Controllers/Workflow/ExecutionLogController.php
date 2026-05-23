<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Resources\Workflow\ExecutionLogResource;
use App\Models\ExecutionLog;
use App\Models\WorkflowRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExecutionLogController extends Controller
{
    public function __invoke(Request $request, string $run): AnonymousResourceCollection
    {
        $model = WorkflowRun::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($run);

        $perPage = min((int) $request->integer('per_page', 50), 200);

        $logs = ExecutionLog::query()
            ->where('workflow_run_id', $model->id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderBy('created_at')
            ->paginate($perPage);

        return ExecutionLogResource::collection($logs);
    }
}
