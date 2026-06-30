<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class License extends Model
{
    use HasUuids, HasFactory, SoftDeletes;

    protected $fillable = [
        'org_id',
        'license_key',
        'plan',
        'type',
        'features',
        'max_users',
        'max_activations',
        'issued_at',
        'expires_at',
        'status',
        'issued_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'max_users' => 'integer',
            'max_activations' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function activeActivations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class)->where('status', 'active');
    }

    public function revocation(): HasOne
    {
        return $this->hasOne(RevocationList::class)->latest('revoked_at');
    }

    public function revocations(): HasMany
    {
        return $this->hasMany(RevocationList::class);
    }

    public function usageMetrics(): HasMany
    {
        return $this->hasMany(LicenseUsageMetric::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }

    /**
     * Whether this license is a time-boxed evaluation type (trial/demo/poc).
     */
    public function isTrialType(): bool
    {
        return (bool) config('licensing.types.' . ($this->type ?? 'full') . '.trial', false);
    }

    /**
     * Per-plan grace window in days (used after expiry). Falls back to the
     * global default. This is the single source of truth for grace duration
     * across activate/heartbeat responses.
     */
    public function gracePeriodDays(): int
    {
        return (int) config(
            'licensing.plans.' . $this->plan . '.grace_days',
            config('licensing.grace_period_days', 7),
        );
    }
}
