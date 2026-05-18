<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuids, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'contact_email',
        'industry',
        'country',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class, 'org_id');
    }

    public function apiClients(): HasMany
    {
        return $this->hasMany(ApiClient::class, 'org_id');
    }
}
