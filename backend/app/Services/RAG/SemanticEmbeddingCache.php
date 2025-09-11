<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SemanticEmbeddingCache
{
    private const CACHE_TTL = 3600; // 1 ora
    private const SIMILARITY_THRESHOLD = 0.95; // 95% similarità per cache hit
    private const MAX_CACHE_ENTRIES = 1000; // Limite cache entries per tenant
    
    private array $sessionCache = []; // Cache temporanea per la sessione corrente
    
    /**
     * Ottiene embedding dalla cache se disponibile, altrimenti null
     */
    public function getEmbedding(string $text, int $tenantId): ?array
    {
        $normalizedText = $this->normalizeText($text);
        $textHash = $this->getTextHash($normalizedText);
        
        // 1. Controlla cache sessione prima (più veloce)
        if (isset($this->sessionCache[$textHash])) {
            Log::debug('📋 [SEMANTIC_CACHE] Session cache hit', [
                'text_preview' => substr($text, 0, 50),
                'hash' => $textHash
            ]);
            return $this->sessionCache[$textHash]['embedding'];
        }
        
        // 2. Controlla cache persistente
        $cacheKey = $this->getCacheKey($tenantId, $textHash);
        $cachedData = Cache::get($cacheKey);
        
        if ($cachedData) {
            // Aggiungi alla session cache per accesso più veloce
            $this->sessionCache[$textHash] = $cachedData;
            
            Log::debug('💾 [SEMANTIC_CACHE] Persistent cache hit', [
                'text_preview' => substr($text, 0, 50),
                'cache_age' => now()->diffInMinutes($cachedData['created_at'])
            ]);
            
            return $cachedData['embedding'];
        }
        
        // 3. Ricerca semantica per testi simili (costosa, solo se necessario)
        $similarEmbedding = $this->findSimilarEmbedding($normalizedText, $tenantId);
        
        if ($similarEmbedding) {
            // Cache il risultato per future richieste
            $this->cacheEmbedding($normalizedText, $similarEmbedding, $tenantId);
            
            Log::info('🎯 [SEMANTIC_CACHE] Semantic similarity hit', [
                'text_preview' => substr($text, 0, 50),
                'similarity_score' => $similarEmbedding['similarity']
            ]);
            
            return $similarEmbedding['embedding'];
        }
        
        return null;
    }
    
    /**
     * Salva embedding nella cache
     */
    public function cacheEmbedding(string $text, array $embedding, int $tenantId): void
    {
        $normalizedText = $this->normalizeText($text);
        $textHash = $this->getTextHash($normalizedText);
        
        $data = [
            'text' => $normalizedText,
            'embedding' => $embedding,
            'created_at' => now(),
            'access_count' => 1,
            'last_accessed' => now()
        ];
        
        // Cache sessione
        $this->sessionCache[$textHash] = $data;
        
        // Cache persistente
        $cacheKey = $this->getCacheKey($tenantId, $textHash);
        Cache::put($cacheKey, $data, self::CACHE_TTL);
        
        // Aggiorna indice per ricerca semantica
        $this->updateSemanticIndex($normalizedText, $embedding, $tenantId);
        
        Log::debug('💾 [SEMANTIC_CACHE] Cached new embedding', [
            'text_preview' => substr($text, 0, 50),
            'embedding_dims' => count($embedding)
        ]);
    }
    
    /**
     * Batch caching per multiple embeddings
     */
    public function cacheBatch(array $texts, array $embeddings, int $tenantId): void
    {
        $startTime = microtime(true);
        
        foreach ($texts as $i => $text) {
            if (isset($embeddings[$i]) && $embeddings[$i] !== null) {
                $this->cacheEmbedding($text, $embeddings[$i], $tenantId);
            }
        }
        
        $endTime = microtime(true);
        $processingTime = round(($endTime - $startTime) * 1000, 2);
        
        Log::info('💾 [SEMANTIC_CACHE] Batch cached', [
            'texts_count' => count($texts),
            'embeddings_cached' => count(array_filter($embeddings)),
            'processing_time_ms' => $processingTime
        ]);
    }
    
    /**
     * Ricerca embedding simili nell'indice semantico
     */
    private function findSimilarEmbedding(string $text, int $tenantId): ?array
    {
        $indexKey = $this->getSemanticIndexKey($tenantId);
        $index = Cache::get($indexKey, []);
        
        if (empty($index)) {
            return null;
        }
        
        // Calcola embedding del testo target per confronto
        // Nota: questo è costoso, ma solo se non in cache
        $tempEmbedding = $this->getQuickEmbedding($text);
        if (!$tempEmbedding) {
            return null;
        }
        
        $bestMatch = null;
        $bestSimilarity = 0;
        
        foreach ($index as $entry) {
            $similarity = $this->cosineSimilarity($tempEmbedding, $entry['embedding']);
            
            if ($similarity >= self::SIMILARITY_THRESHOLD && $similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = [
                    'embedding' => $entry['embedding'],
                    'similarity' => $similarity,
                    'original_text' => $entry['text']
                ];
            }
        }
        
        return $bestMatch;
    }
    
    /**
     * Aggiorna indice semantico per ricerca similarità
     */
    private function updateSemanticIndex(string $text, array $embedding, int $tenantId): void
    {
        $indexKey = $this->getSemanticIndexKey($tenantId);
        $index = Cache::get($indexKey, []);
        
        // Aggiungi nuovo entry
        $textHash = $this->getTextHash($text);
        $index[$textHash] = [
            'text' => $text,
            'embedding' => $embedding,
            'created_at' => now()->timestamp
        ];
        
        // Mantieni solo i più recenti se supera il limite
        if (count($index) > self::MAX_CACHE_ENTRIES) {
            // Ordina per timestamp e mantieni i più recenti
            uasort($index, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
            $index = array_slice($index, 0, self::MAX_CACHE_ENTRIES, true);
        }
        
        Cache::put($indexKey, $index, self::CACHE_TTL);
    }
    
    /**
     * Ottiene embedding veloce per confronto (usa cache se disponibile)
     */
    private function getQuickEmbedding(string $text): ?array
    {
        // Implementazione semplificata - in produzione userebbe un servizio leggero
        // Per ora ritorna null per evitare chiamate API ricorsive
        return null;
    }
    
    /**
     * Calcola similarità coseno tra due embedding
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }
        
        return $dotProduct / ($normA * $normB);
    }
    
    /**
     * Normalizza testo per cache consistency
     */
    private function normalizeText(string $text): string
    {
        // Rimuovi spazi extra, converti in lowercase, trimma
        return trim(preg_replace('/\s+/', ' ', strtolower($text)));
    }
    
    /**
     * Genera hash deterministico per il testo
     */
    private function getTextHash(string $text): string
    {
        return hash('xxh3', $text);
    }
    
    /**
     * Chiave cache per tenant specifico
     */
    private function getCacheKey(int $tenantId, string $textHash): string
    {
        return "semantic_embedding_cache:tenant_{$tenantId}:{$textHash}";
    }
    
    /**
     * Chiave indice semantico per tenant
     */
    private function getSemanticIndexKey(int $tenantId): string
    {
        return "semantic_embedding_index:tenant_{$tenantId}";
    }
    
    /**
     * Statistiche cache per debugging
     */
    public function getStats(int $tenantId): array
    {
        $indexKey = $this->getSemanticIndexKey($tenantId);
        $index = Cache::get($indexKey, []);
        
        return [
            'session_cache_size' => count($this->sessionCache),
            'persistent_index_size' => count($index),
            'cache_hit_ratio' => $this->calculateHitRatio($tenantId),
            'oldest_entry' => !empty($index) ? min(array_column($index, 'created_at')) : null,
        ];
    }
    
    /**
     * Calcola hit ratio approssimativo
     */
    private function calculateHitRatio(int $tenantId): float
    {
        // Implementazione semplificata - in produzione trackerebbero hit/miss
        return 0.0;
    }
    
    /**
     * Pulisce cache scaduta
     */
    public function cleanup(int $tenantId): void
    {
        $this->sessionCache = [];
        
        $indexKey = $this->getSemanticIndexKey($tenantId);
        $index = Cache::get($indexKey, []);
        
        $cutoff = now()->subHours(2)->timestamp;
        $cleaned = array_filter($index, fn($entry) => $entry['created_at'] > $cutoff);
        
        if (count($cleaned) !== count($index)) {
            Cache::put($indexKey, $cleaned, self::CACHE_TTL);
            
            Log::info('🧹 [SEMANTIC_CACHE] Cleanup completed', [
                'tenant_id' => $tenantId,
                'entries_before' => count($index),
                'entries_after' => count($cleaned),
                'entries_removed' => count($index) - count($cleaned)
            ]);
        }
    }
}
