<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScraperProgress extends Model
{
    protected $table = 'scraper_progress';
    
    protected $fillable = [
        'tenant_id',
        'scraper_config_id',
        'session_id',
        'status',
        'pages_found',
        'pages_scraped',
        'pages_skipped',
        'pages_failed',
        'documents_created',
        'documents_updated',
        'documents_unchanged',
        'ingestion_pending',
        'ingestion_processing',
        'ingestion_completed',
        'ingestion_failed',
        'current_url',
        'current_depth',
        'last_error',
        'urls_queue',
        'started_at',
        'completed_at',
        'estimated_duration_seconds',
    ];

    protected $casts = [
        'urls_queue' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scraperConfig(): BelongsTo
    {
        return $this->belongsTo(ScraperConfig::class);
    }

    /**
     * Aggiorna contatori in modo atomico
     */
    public function incrementCounter(string $counter, int $amount = 1): void
    {
        $this->increment($counter, $amount);
    }

    /**
     * Calcola percentuale di completamento
     */
    public function getProgressPercentage(): float
    {
        if ($this->pages_found <= 0) return 0;
        
        $processed = $this->pages_scraped + $this->pages_skipped + $this->pages_failed;
        return min(100, ($processed / $this->pages_found) * 100);
    }

    /**
     * Calcola percentuale ingestion
     */
    public function getIngestionPercentage(): float
    {
        $total = $this->documents_created + $this->documents_updated;
        if ($total <= 0) return 0;
        
        $completed = $this->ingestion_completed + $this->ingestion_failed;
        return min(100, ($completed / $total) * 100);
    }

    /**
     * Ottieni summary status per UI
     */
    public function getSummary(): array
    {
        return [
            'session_id' => $this->session_id,
            'status' => $this->status,
            'progress_percentage' => $this->getProgressPercentage(),
            'ingestion_percentage' => $this->getIngestionPercentage(),
            'pages' => [
                'found' => $this->pages_found,
                'scraped' => $this->pages_scraped,
                'skipped' => $this->pages_skipped,
                'failed' => $this->pages_failed,
            ],
            'documents' => [
                'created' => $this->documents_created,
                'updated' => $this->documents_updated,
                'unchanged' => $this->documents_unchanged,
            ],
            'ingestion' => [
                'pending' => $this->ingestion_pending,
                'processing' => $this->ingestion_processing,
                'completed' => $this->ingestion_completed,
                'failed' => $this->ingestion_failed,
            ],
            'timing' => [
                'started_at' => $this->started_at,
                'completed_at' => $this->completed_at,
                'estimated_duration' => $this->estimated_duration_seconds,
                'elapsed_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : 0,
            ],
            'current' => [
                'url' => $this->current_url,
                'depth' => $this->current_depth,
                'error' => $this->last_error,
            ]
        ];
    }
}
