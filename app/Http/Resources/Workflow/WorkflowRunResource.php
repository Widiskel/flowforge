<?php

declare(strict_types=1);

namespace App\Http\Resources\Workflow;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'workflow_version_id' => $this->workflow_version_id,
            'workflow_trigger_id' => $this->workflow_trigger_id,
            'status' => $this->status,
            'input' => $this->input,
            'timeout_ms' => $this->timeout_ms,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'created_by' => $this->created_by,
            'step_runs' => WorkflowStepRunResource::collection($this->whenLoaded('stepRuns')),
            'logs' => ExecutionLogResource::collection($this->whenLoaded('logs')),
        ];
    }
}
