<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'workflow_id',
        'version_number',
        'definition',
        'source',
        'change_summary',
        'rolled_back_from_version_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'version_number' => 'integer',
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

    public function rolledBackFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolled_back_from_version_id');
    }
}
