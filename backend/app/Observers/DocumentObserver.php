<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\RAG\MilvusClient;
use Illuminate\Support\Facades\Log;

class DocumentObserver
{
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "deleted" event.
     * 
     * Sincronizza la cancellazione con Milvus per evitare documenti zombie.
     */
    public function deleted(Document $document): void
    {
        try {
            // Conta i chunk prima di cancellarli (potrebbero essere già cancellati da cascadeOnDelete)
            $chunkCount = $document->chunks()->count();
            
            if ($chunkCount === 0) {
                // Prova a stimare dal document se non ci sono chunk in DB
                // Usa un numero ragionevole come fallback (es. 10 chunk max)
                $chunkCount = 10;
                Log::warning('Document deleted without chunks in DB, using fallback chunk count', [
                    'document_id' => $document->id,
                    'tenant_id' => $document->tenant_id,
                    'fallback_chunks' => $chunkCount,
                ]);
            }
            
            // Calcola primary IDs dei chunk in Milvus
            // Formula: primary_id = (document_id * 100000) + chunk_index
            $primaryIds = [];
            for ($i = 0; $i < $chunkCount; $i++) {
                $primaryIds[] = ($document->id * 100000) + $i;
            }
            
            if (empty($primaryIds)) {
                Log::info('No chunks to delete from Milvus', [
                    'document_id' => $document->id,
                    'tenant_id' => $document->tenant_id,
                ]);
                return;
            }
            
            // Cancella da Milvus
            $milvus = app(MilvusClient::class);
            $result = $milvus->deleteByPrimaryIds($primaryIds);
            
            if ($result['success'] ?? false) {
                Log::info('✅ Document chunks deleted from Milvus', [
                    'document_id' => $document->id,
                    'tenant_id' => $document->tenant_id,
                    'chunk_count' => $chunkCount,
                    'primary_ids_deleted' => count($primaryIds),
                    'milvus_deleted_count' => $result['deleted_count'] ?? 0,
                ]);
            } else {
                Log::error('❌ Failed to delete document chunks from Milvus', [
                    'document_id' => $document->id,
                    'tenant_id' => $document->tenant_id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'primary_ids' => $primaryIds,
                ]);
            }
        } catch (\Exception $e) {
            // Non bloccare la cancellazione del documento se Milvus fallisce
            Log::error('❌ Exception deleting document chunks from Milvus', [
                'document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "force deleted" event.
     * 
     * Sincronizza anche il force delete con Milvus.
     */
    public function forceDeleted(Document $document): void
    {
        // Usa la stessa logica del soft delete
        $this->deleted($document);
    }
}
