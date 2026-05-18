<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'action',
        'actor_type',
        'actor_id',
        'resource_type',
        'resource_id',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?array $metadata = null,
        ?string $actorType = null,
        ?string $actorId = null,
    ): static {
        return static::create([
            'action' => $action,
            'actor_type' => $actorType ?? (auth()->check() ? 'admin' : 'system'),
            'actor_id' => $actorId ?? auth()->id(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
