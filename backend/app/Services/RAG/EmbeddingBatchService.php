<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIEmbeddingsService;
use Illuminate\Support\Facades\Log;

class EmbeddingBatchService
{
    private array $pendingTexts = [];
    private array $batchResults = [];
    private bool $batchProcessed = false;
    private int $tenantId = 0;
    
    public function __construct(
        private readonly OpenAIEmbeddingsService $embeddings,
        private readonly ?SemanticEmbeddingCache $semanticCache = null
    ) {}
    
    /**
     * Imposta tenant ID per semantic caching
     */
    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }
    
    /**
     * Aggiunge testi al batch senza calcolare subito gli embeddings
     */
    public function addToBatch(array $texts, string $type = 'general'): array
    {
        $indexes = [];
        foreach ($texts as $text) {
            $textHash = $this->getTextHash($text);
            
            if (!isset($this->pendingTexts[$textHash])) {
                $this->pendingTexts[$textHash] = [
                    'text' => $text,
                    'type' => $type,
                    'index' => count($this->pendingTexts),
                    'processed' => false
                ];
            }
            
            $indexes[] = $this->pendingTexts[$textHash]['index'];
        }
        
        return $indexes;
    }
    
    /**
     * Processa tutto il batch con semantic caching
     */
    public function processBatch(): void
    {
        if ($this->batchProcessed || empty($this->pendingTexts)) {
            return;
        }
        
        $startTime = microtime(true);
        
        // 🚀 SEMANTIC CACHE: Controlla cache prima di chiamare API
        $textsToProcess = [];
        $indexMap = [];
        $cacheHits = 0;
        $cacheMisses = 0;
        
        foreach ($this->pendingTexts as $hash => $data) {
            $text = $data['text'];
            $index = $data['index'];
            
            // Controlla semantic cache se disponibile
            $cachedEmbedding = null;
            if ($this->semanticCache && $this->tenantId > 0) {
                $cachedEmbedding = $this->semanticCache->getEmbedding($text, $this->tenantId);
            }
            
            if ($cachedEmbedding !== null) {
                // Cache hit - usa embedding dalla cache
                $this->batchResults[$hash] = [
                    'embedding' => $cachedEmbedding,
                    'text' => $text,
                    'original_index' => $index,
                    'cache_hit' => true
                ];
                $cacheHits++;
            } else {
                // Cache miss - aggiungi alla lista per API call
                $textsToProcess[$index] = $text;
                $indexMap[$index] = $hash;
                $cacheMisses++;
            }
        }
        
        ksort($textsToProcess); // Mantieni l'ordine
        
        Log::info('🚀 [EMBEDDING_BATCH] Processando batch con semantic cache', [
            'total_texts' => count($this->pendingTexts),
            'cache_hits' => $cacheHits,
            'cache_misses' => $cacheMisses,
            'cache_hit_ratio' => count($this->pendingTexts) > 0 ? round(($cacheHits / count($this->pendingTexts)) * 100, 2) . '%' : '0%',
            'api_calls_needed' => !empty($textsToProcess) ? 1 : 0
        ]);
        
        // Chiamata API solo per cache misses
        $newEmbeddings = [];
        if (!empty($textsToProcess)) {
            $newEmbeddings = $this->embeddings->embedTexts(array_values($textsToProcess));
            
            // Cache i nuovi embeddings per future richieste
            if ($this->semanticCache && $this->tenantId > 0) {
                $this->semanticCache->cacheBatch(array_values($textsToProcess), $newEmbeddings, $this->tenantId);
            }
        }
        
        // Mappa risultati delle API calls
        $embeddingIndex = 0;
        foreach ($textsToProcess as $index => $text) {
            $hash = $indexMap[$index];
            
            $this->batchResults[$hash] = [
                'embedding' => $newEmbeddings[$embeddingIndex] ?? null,
                'text' => $text,
                'original_index' => $index,
                'cache_hit' => false
            ];
            
            $embeddingIndex++;
        }
        
        // Marca tutti come processati
        foreach ($this->pendingTexts as $hash => $data) {
            $this->pendingTexts[$hash]['processed'] = true;
        }
        
        $this->batchProcessed = true;
        
        $endTime = microtime(true);
        $processingTime = round(($endTime - $startTime) * 1000, 2);
        
        Log::info('✅ [EMBEDDING_BATCH] Batch completato con semantic caching', [
            'processing_time_ms' => $processingTime,
            'texts_processed' => count($this->pendingTexts),
            'api_calls_saved' => count($this->pendingTexts) - (empty($textsToProcess) ? 0 : 1),
            'cache_efficiency' => $cacheHits > 0 ? round(($cacheHits / count($this->pendingTexts)) * 100, 2) . '%' : '0%'
        ]);
    }
    
    /**
     * Recupera embeddings dal batch processato
     */
    public function getEmbeddings(array $texts): array
    {
        if (!$this->batchProcessed) {
            $this->processBatch();
        }
        
        $results = [];
        foreach ($texts as $text) {
            $hash = $this->getTextHash($text);
            $results[] = $this->batchResults[$hash]['embedding'] ?? null;
        }
        
        return $results;
    }
    
    /**
     * Recupera un singolo embedding dal batch
     */
    public function getEmbedding(string $text): ?array
    {
        $embeddings = $this->getEmbeddings([$text]);
        return $embeddings[0] ?? null;
    }
    
    /**
     * Reset del batch per nuova sessione
     */
    public function reset(): void
    {
        $this->pendingTexts = [];
        $this->batchResults = [];
        $this->batchProcessed = false;
    }
    
    /**
     * Statistiche del batch corrente
     */
    public function getStats(): array
    {
        return [
            'pending_texts' => count($this->pendingTexts),
            'processed' => $this->batchProcessed,
            'deduplication_ratio' => count($this->pendingTexts) > 0 
                ? round((1 - count($this->pendingTexts) / max(count($this->pendingTexts), 1)) * 100, 2)
                : 0,
        ];
    }
    
    /**
     * Hash deterministico per deduplicazione
     */
    private function getTextHash(string $text): string
    {
        // Normalizza il testo per deduplicazione
        $normalized = trim(strtolower($text));
        return hash('xxh3', $normalized);
    }
}
