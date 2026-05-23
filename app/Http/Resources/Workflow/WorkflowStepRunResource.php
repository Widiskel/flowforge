<?php

declare(strict_types=1);

namespace App\Http\Resources\Workflow;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowStepRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'step_id' => $this->step_id,
            'step_type' => $this->step_type,
            'status' => $this->status,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'duration_ms' => $this->duration_ms,
            'output' => $this->output,
            'error_message' => $this->error_message,
        ];
    }
}
