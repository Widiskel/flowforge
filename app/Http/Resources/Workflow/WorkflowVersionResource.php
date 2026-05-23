<?php

declare(strict_types=1);

namespace App\Http\Resources\Workflow;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'version_number' => $this->version_number,
            'definition' => $this->definition,
            'source' => $this->source,
            'change_summary' => $this->change_summary,
            'rolled_back_from_version_id' => $this->rolled_back_from_version_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
