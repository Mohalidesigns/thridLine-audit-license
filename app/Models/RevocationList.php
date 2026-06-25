<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevocationList extends Model
{
    use HasUuids;

    protected $table = 'revocation_list';

    protected $fillable = [
        'license_id',
        'reason',
        'revoked_by',
        'revoked_at',
        'effective_at',
        'cancelled_at',
        'cancelled_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'effective_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Revocations that have not been cancelled by an admin.
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * Revocations whose effect is already in force (immediate, or scheduled
     * with the effective time now in the past). A null effective_at is treated
     * as immediate for backward-compatibility with legacy rows.
     */
    public function scopeInEffect(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->where(function (Builder $q) {
                $q->whereNull('effective_at')
                  ->orWhere('effective_at', '<=', now());
            });
    }

    /**
     * Scheduled revokes still waiting for their effective time — not cancelled,
     * not yet applied, effective in the future.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')
            ->whereNull('applied_at')
            ->whereNotNull('effective_at')
            ->where('effective_at', '>', now());
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isCancelled()
            && $this->applied_at === null
            && $this->effective_at !== null
            && $this->effective_at->isFuture();
    }
}
