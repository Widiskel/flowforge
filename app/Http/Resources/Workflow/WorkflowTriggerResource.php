<?php

declare(strict_types=1);

namespace App\Http\Resources\Workflow;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowTriggerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'type' => $this->type,
            'webhook_secret' => $this->when($this->type === 'webhook', fn () => '***'),
            'cron_expression' => $this->cron_expression,
            'timezone' => $this->timezone,
            'enabled' => $this->enabled,
            'next_run_at' => $this->next_run_at?->toISOString(),
            'last_run_at' => $this->last_run_at?->toISOString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
