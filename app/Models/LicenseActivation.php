<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicenseActivation extends Model
{
    use HasUuids, HasFactory;

    protected $fillable = [
        'license_id',
        'device_fingerprint',
        'hostname',
        'ip_address',
        'os_info',
        'activated_at',
        'last_seen_at',
        'status',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function usageMetrics(): HasMany
    {
        return $this->hasMany(LicenseUsageMetric::class, 'activation_id');
    }
}
