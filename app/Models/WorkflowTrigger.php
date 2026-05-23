<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTrigger extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_id',
        'type',
        'webhook_secret',
        'cron_expression',
        'timezone',
        'enabled',
        'next_run_at',
        'last_run_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
