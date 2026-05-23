<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStepRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_run_id',
        'step_id',
        'step_type',
        'status',
        'attempt_count',
        'max_attempts',
        'started_at',
        'finished_at',
        'duration_ms',
        'output',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
