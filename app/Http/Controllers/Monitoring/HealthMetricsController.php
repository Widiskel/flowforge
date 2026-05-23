<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\WorkflowRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HealthMetricsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $since = Carbon::now()->subHours(24);

        $runs = WorkflowRun::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->get(['status', 'duration_ms', 'started_at', 'finished_at']);

        $total = $runs->count();
        $success = $runs->where('status', 'SUCCESS')->count();
        $failed = $runs->where('status', 'FAILED')->count();
        $timeout = $runs->where('status', 'TIMEOUT')->count();
        $active = $runs->whereIn('status', ['PENDING', 'RUNNING'])->count();

        $durations = $runs->whereNotNull('duration_ms')->pluck('duration_ms')->sort()->values();
        $avgDuration = $durations->isNotEmpty() ? (int) round($durations->avg()) : null;
        $p95Duration = $durations->isNotEmpty()
            ? (int) $durations->get((int) floor($durations->count() * 0.95))
            : null;

        return response()->json([
            'data' => [
                'window' => 'last_24h',
                'generated_at' => Carbon::now()->toISOString(),
                'active_runs' => $active,
                'totals' => [
                    'runs' => $total,
                    'success' => $success,
                    'failed' => $failed,
                    'timeout' => $timeout,
                ],
                'rates' => [
                    'success' => $total > 0 ? round($success / $total, 3) : 0,
                    'failure' => $total > 0 ? round($failed / $total, 3) : 0,
                    'timeout' => $total > 0 ? round($timeout / $total, 3) : 0,
                ],
                'average_duration_ms' => $avgDuration,
                'p95_duration_ms' => $p95Duration,
            ],
        ]);
    }
}
