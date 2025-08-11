<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'title',
        'source',
        'path',
        'metadata',
        'ingestion_status',
        'ingestion_progress',
        'last_error',
    ];

    protected $casts = [
        'metadata' => 'array',
        'ingestion_progress' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}



