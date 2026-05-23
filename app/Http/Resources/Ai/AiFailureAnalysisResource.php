<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiFailureAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_run_id' => $this->workflow_run_id,
            'workflow_step_run_id' => $this->workflow_step_run_id,
            'attempt_count' => $this->attempt_count,
            'root_cause' => $this->root_cause,
            'suggested_fix' => $this->suggested_fix,
            'confidence' => $this->confidence,
            'category' => $this->category,
            'evidence' => $this->evidence,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
