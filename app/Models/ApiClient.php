<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiClient extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id',
        'client_id',
        'client_secret_hash',
        'allowed_scopes',
        'allowed_ips',
        'is_active',
    ];

    protected $hidden = [
        'client_secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'allowed_ips' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->allowed_scopes ?? []);
    }
}
