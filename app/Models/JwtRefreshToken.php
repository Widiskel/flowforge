<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JwtRefreshToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'replaced_by_id',
        'is_api_token',
        'label',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'is_api_token' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
