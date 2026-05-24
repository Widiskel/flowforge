<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_id',
        'workflow_version_id',
        'workflow_trigger_id',
        'created_by',
        'status',
        'input',
        'timeout_ms',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function stepRuns(): HasMany
    {
        // Order by id (UUIDv7 is timestamp-prefixed) so the executor's
        // insertion sequence — which mirrors the workflow's topological
        // execution order — is preserved when the resource layer hydrates
        // the relation.
        return $this->hasMany(WorkflowStepRun::class)->orderBy('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function aiAuditLogs(): HasMany
    {
        return $this->hasMany(AiAuditLog::class);
    }
}
