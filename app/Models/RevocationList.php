<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'effective_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
