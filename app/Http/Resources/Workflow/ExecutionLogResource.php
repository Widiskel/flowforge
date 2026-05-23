<?php

declare(strict_types=1);

namespace App\Http\Resources\Workflow;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExecutionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_step_run_id' => $this->workflow_step_run_id,
            'level' => $this->level,
            'event' => $this->event,
            'message' => $this->message,
            'context' => $this->context,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
