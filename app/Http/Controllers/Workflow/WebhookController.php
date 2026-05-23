<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workflow;

use App\Actions\Workflow\TriggerWorkflowAction;
use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $workflow): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id ?? $request->header('X-Tenant-ID');

        $trigger = WorkflowTrigger::query()
            ->where('type', 'webhook')
            ->where('enabled', true)
            ->whereHas('workflow', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->where('workflow_id', $workflow)
            ->firstOrFail();

        $signature = $request->header('X-FlowForge-Signature');
        if (! $signature || ! $this->verifySignature($request->getContent(), $trigger->webhook_secret, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $action = app(TriggerWorkflowAction::class);
        $workflowModel = Workflow::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $workflow)
            ->firstOrFail();

        $action->execute($request->user() ?? $trigger->creator, $workflowModel, $request->json()->all());

        return response()->json(['message' => 'Triggered'], 202);
    }

    private function verifySignature(string $payload, string $secret, string $signature): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
