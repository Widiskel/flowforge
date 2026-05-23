<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Domain\Workflow\Enums\RunStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Workflow\WorkflowRunResource;
use App\Models\WorkflowRun;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunEventStreamController extends Controller
{
    public function __invoke(Request $request, string $run): StreamedResponse
    {
        $model = WorkflowRun::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($run);

        $maxTicks = min((int) $request->integer('max_ticks', 60), 120);
        $intervalMs = max((int) $request->integer('interval_ms', 1000), 500);
        $heartbeatInterval = 15;

        return new StreamedResponse(function () use ($model, $maxTicks, $intervalMs, $heartbeatInterval): void {
            $tick = 0;
            $lastHeartbeat = time();

            while ($tick < $maxTicks) {
                if (connection_aborted()) {
                    break;
                }

                $model->refresh();
                $model->load(['stepRuns', 'logs']);

                $snapshot = (new WorkflowRunResource($model))->resolve();

                echo "event: run.snapshot\n";
                echo 'data: '.json_encode($snapshot, JSON_THROW_ON_ERROR)."\n\n";

                ob_flush();
                flush();

                $status = RunStatus::tryFrom($model->status);

                if ($status !== null && $status->isTerminal()) {
                    echo "event: run.completed\n";
                    echo "data: {$model->status}\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                $tick++;
                usleep($intervalMs * 1000);

                if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                    echo ": heartbeat\n\n";
                    ob_flush();
                    flush();
                    $lastHeartbeat = time();
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
