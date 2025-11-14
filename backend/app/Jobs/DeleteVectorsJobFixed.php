<?php

namespace App\Jobs;

use App\Services\RAG\MilvusClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteVectorsJobFixed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int> Primary IDs da cancellare da Milvus */
    private array $primaryIds;

    /** @var array<int> Document IDs per logging */
    private array $documentIds;

    /**
     * 🚀 SOLUZIONE: Passiamo direttamente i primaryIds invece di calcolarli nel job
     * 🛡️ SAFETY: Parametri opzionali per evitare errori se chiamato senza parametri
     */
    public function __construct(array $primaryIds = [], array $documentIds = [])
    {
        $this->onQueue('indexing');
        $this->primaryIds = array_values(array_unique(array_map('intval', $primaryIds)));
        $this->documentIds = array_values(array_unique(array_map('intval', $documentIds)));

        // 🐛 DEBUG: Log se chiamato senza parametri per tracciare la source
        if (empty($primaryIds) && empty($documentIds)) {
            Log::warning('DeleteVectorsJobFixed chiamato senza parametri!', [
                'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
            ]);
        }
    }

    /**
     * 🚀 NUOVO: Factory method per creare il job dai document IDs
     * Calcola i primaryIds PRIMA che i chunks vengano cancellati
     */
    public static function fromDocumentIds(array $documentIds): self
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

        if (empty($documentIds)) {
            return new self([], []);
        }

        // 📊 Calcola primaryIds PRIMA che i chunks vengano cancellati
        $rows = DB::table('document_chunks')
            ->whereIn('document_id', $documentIds)
            ->select('document_id', 'chunk_index')
            ->get();

        $primaryIds = [];
        foreach ($rows as $r) {
            $primaryIds[] = (int) (((int) $r->document_id) * 100000 + (int) $r->chunk_index);
        }

        Log::info('DeleteVectorsJobFixed.factory', [
            'document_ids' => $documentIds,
            'chunks_found' => $rows->count(),
            'primary_ids_calculated' => $primaryIds,
        ]);

        return new self($primaryIds, $documentIds);
    }

    public function handle(MilvusClient $milvus): void
    {
        if (empty($this->primaryIds)) {
            Log::warning('DeleteVectorsJobFixed.no_primary_ids', [
                'document_ids' => $this->documentIds,
            ]);

            return;
        }

        try {
            Log::info('DeleteVectorsJobFixed.start', [
                'document_ids' => $this->documentIds,
                'primary_ids_count' => count($this->primaryIds),
                'primary_ids' => $this->primaryIds,
            ]);

            $milvus->deleteByPrimaryIds($this->primaryIds);

            Log::info('DeleteVectorsJobFixed.success', [
                'document_ids' => $this->documentIds,
                'primary_ids_deleted' => count($this->primaryIds),
            ]);

        } catch (\Throwable $e) {
            Log::error('DeleteVectorsJobFixed.failed', [
                'document_ids' => $this->documentIds,
                'primary_ids' => $this->primaryIds,
                'error' => $e->getMessage(),
            ]);

            // Re-throw per permettere retry del job se configurato
            throw $e;
        }
    }
}
