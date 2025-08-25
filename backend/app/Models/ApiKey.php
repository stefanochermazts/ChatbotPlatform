<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'key',
        'key_hash',
        'scopes',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'revoked_at' => 'datetime',
        'key' => 'encrypted',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}



