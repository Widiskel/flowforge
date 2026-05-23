<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\OperationalHealthService;
use Illuminate\Http\JsonResponse;

class OperationalProbeController extends Controller
{
    public function __construct(
        private readonly OperationalHealthService $healthService,
    ) {}

    public function ready(): JsonResponse
    {
        $result = $this->healthService->check();
        $status = $result['status'] === 'healthy' ? 200 : 503;

        return response()->json($result, $status);
    }

    public function startup(): JsonResponse
    {
        $result = $this->healthService->check();
        $status = $result['status'] === 'healthy' ? 200 : 503;

        return response()->json($result, $status);
    }

    public function actuator(): JsonResponse
    {
        $result = $this->healthService->check();
        $status = $result['status'] === 'healthy' ? 200 : 503;

        return response()->json([
            'status' => $result['status'] === 'healthy' ? 'UP' : 'DOWN',
            'components' => collect($result['checks'])->map(fn (array $check) => [
                'status' => $check['status'] === 'up' ? 'UP' : 'DOWN',
            ])->all(),
            'timestamp' => $result['timestamp'],
        ], $status);
    }
}
