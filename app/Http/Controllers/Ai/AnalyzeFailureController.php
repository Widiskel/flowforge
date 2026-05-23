<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Actions\Ai\AnalyzeRunFailureAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Ai\AiFailureAnalysisResource;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyzeFailureController extends Controller
{
    public function __invoke(Request $request, string $run, AnalyzeRunFailureAction $action): JsonResponse
    {
        $model = WorkflowRun::query()
            ->with('stepRuns')
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($run);

        if (! in_array($model->status, ['FAILED', 'TIMEOUT'], true)) {
            return response()->json([
                'message' => 'Only failed or timed-out runs can be analyzed.',
            ], 422);
        }

        $this->authorize('trigger', $model->workflow);

        $force = (bool) $request->boolean('force', false);

        $analysis = $action->execute($request->user(), $model, $force);

        return (new AiFailureAnalysisResource($analysis))
            ->response()
            ->setStatusCode(200);
    }
}
