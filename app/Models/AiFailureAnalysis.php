<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFailureAnalysis extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_run_id',
        'workflow_step_run_id',
        'attempt_count',
        'root_cause',
        'suggested_fix',
        'confidence',
        'category',
        'evidence',
        'redacted_prompt_context',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'redacted_prompt_context' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
