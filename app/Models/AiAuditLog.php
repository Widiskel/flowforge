<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAuditLog extends Model
{
    use HasUuids;

    protected $table = 'ai_audit_log';

    protected $fillable = [
        'tenant_id',
        'workflow_run_id',
        'workflow_step_run_id',
        'requested_by',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'status',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }
}
