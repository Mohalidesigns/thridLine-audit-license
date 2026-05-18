<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseUsageMetric extends Model
{
    use HasUuids;

    protected $fillable = [
        'license_id',
        'activation_id',
        'active_users_count',
        'feature_usage',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'feature_usage' => 'array',
            'reported_at' => 'datetime',
            'active_users_count' => 'integer',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function activation(): BelongsTo
    {
        return $this->belongsTo(LicenseActivation::class, 'activation_id');
    }
}
