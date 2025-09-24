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
        'knowledge_base_id',
        'title',
        'source',
        'path',
        'extracted_path',
        'source_url',
        'content_hash',
        'last_scraped_at',
        'scrape_version',
        'metadata',
        'ingestion_status',
        'ingestion_progress',
        'last_error',
        'size',
    ];

    protected $casts = [
        'metadata' => 'array',
        'ingestion_progress' => 'integer',
        'scrape_version' => 'integer',
        'last_scraped_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}



