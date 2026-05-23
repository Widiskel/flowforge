<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Domain\Ai\Services\FailureContextBuilder;
use App\Domain\Ai\Services\MockFailureAnalyzer;
use App\Models\AiAuditLog;
use App\Models\AiFailureAnalysis;
use App\Models\User;
use App\Models\WorkflowRun;

class AnalyzeRunFailureAction
{
    public function __construct(
        private readonly FailureContextBuilder $contextBuilder,
        private readonly MockFailureAnalyzer $analyzer,
    ) {}

    public function execute(User $user, WorkflowRun $run, bool $force = false): AiFailureAnalysis
    {
        if (! $force) {
            $cached = AiFailureAnalysis::query()
                ->where('workflow_run_id', $run->id)
                ->where('tenant_id', $user->tenant_id)
                ->latest()
                ->first();

            if ($cached !== null) {
                return $cached;
            }
        }

        $context = $this->contextBuilder->build($run);
        $result = $this->analyzer->analyze($context);

        $analysis = AiFailureAnalysis::create([
            'tenant_id' => $user->tenant_id,
            'workflow_run_id' => $run->id,
            'workflow_step_run_id' => $run->stepRuns->firstWhere('status', 'FAILED')?->id,
            'attempt_count' => AiFailureAnalysis::where('workflow_run_id', $run->id)->count() + 1,
            'root_cause' => $result['root_cause'],
            'suggested_fix' => $result['suggested_fix'],
            'confidence' => $result['confidence'],
            'category' => $result['category'],
            'evidence' => $result['evidence'],
        ]);

        AiAuditLog::create([
            'tenant_id' => $user->tenant_id,
            'workflow_run_id' => $run->id,
            'requested_by' => $user->id,
            'provider' => $result['provider'],
            'model' => $result['model'],
            'prompt_tokens' => $result['prompt_tokens'],
            'completion_tokens' => $result['completion_tokens'],
            'status' => 'completed',
        ]);

        return $analysis;
    }
}
