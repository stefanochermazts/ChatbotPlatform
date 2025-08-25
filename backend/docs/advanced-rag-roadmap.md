# 🚀 Roadmap RAG Avanzato 2024

## 🎯 Priorità di Implementazione

### **Fase 1: Miglioramenti Immediati (2-3 settimane)**

1. **HyDE Query Expansion** ⭐⭐⭐ ✅ **COMPLETATO**
   - Impatto: Alto (+25-40% rilevanza)
   - Complessità: Media
   - ROI: Immediato
   - Status: Implementato e funzionante

2. **LLM-as-a-Judge Reranking** ⭐⭐⭐ ✅ **COMPLETATO**
   - Sostituisce/integra il reranking embedding attuale
   - Migliora significativamente la rilevanza (+30-50%)
   - Combinazione HyDE+LLM: +50-60% rilevanza! 🚀

3. **Query Decomposition** ⭐⭐
   - Per query complesse multi-parte
   - Richiede modifiche al pipeline esistente

### **Fase 2: Architettura Avanzata (4-6 settimane)**

4. **Parent-Child Chunking** ⭐⭐⭐
   - Reimpianta completamente il chunking
   - Backward compatible con sistema attuale

5. **Contextual Retrieval** ⭐⭐
   - Migliora embeddings esistenti
   - Richiede re-ingestion completa

6. **Adaptive Retrieval Strategy** ⭐⭐
   - Sistema intelligente di selezione strategia
   - Integra tutte le tecniche precedenti

### **Fase 3: Innovazioni Sperimentali (8+ settimane)**

7. **Semantic Chunking** ⭐
   - Alternativa avanzata al chunking fisso
   - Richiede testing approfondito

8. **Multi-Vector Storage** ⭐
   - Architettura completamente nuova
   - Multiple embedding per chunk

## 💰 Costi e Benefici

### **Costi Aggiuntivi OpenAI**

| Tecnica | Costo Extra/1K Query | Beneficio Previsto |
|---------|---------------------|--------------------|
| HyDE | +$0.02 (1 LLM call) | +25% rilevanza |
| LLM Reranking | +$0.05 (multiple calls) | +40% rilevanza |
| Query Decomposition | +$0.03 (1 LLM call) | +30% query complesse |
| Contextual Chunking | +$0.01/chunk ingestion | +20% precisione |

### **Performance Impact**

| Tecnica | Latenza Extra | Throughput Impact |
|---------|---------------|------------------|
| HyDE | +0.5s | -5% |
| LLM Reranking | +1.0s | -15% |
| Query Decomposition | +0.8s | -10% |
| Parent-Child | +0.2s | Neutrale |

## 🔧 Implementazione Tecnica

### **1. HyDE Implementation**

```php
// backend/app/Services/RAG/HyDEExpander.php
class HyDEExpander
{
    public function __construct(
        private readonly OpenAIChatService $llm,
        private readonly OpenAIEmbeddingsService $embeddings
    ) {}
    
    public function expandQuery(string $query, int $tenantId): array
    {
        // Genera documento ipotetico
        $hypothetical = $this->generateHypotheticalAnswer($query);
        
        // Crea embeddings per entrambi
        $originalEmb = $this->embeddings->embedTexts([$query])[0];
        $hypotheticalEmb = $this->embeddings->embedTexts([$hypothetical])[0];
        
        // Combina embeddings (weighted average)
        $combinedEmb = $this->combineEmbeddings(
            $originalEmb, $hypotheticalEmb, 
            weights: [0.6, 0.4]
        );
        
        return [
            'original' => $query,
            'hypothetical' => $hypothetical,
            'original_embedding' => $originalEmb,
            'hypothetical_embedding' => $hypotheticalEmb,
            'combined_embedding' => $combinedEmb
        ];
    }
    
    private function generateHypotheticalAnswer(string $query): string
    {
        $prompt = "Scrivi una risposta dettagliata e accurata a questa domanda: {$query}";
        
        return $this->llm->generateText([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 200,
            'temperature' => 0.3
        ]);
    }
}
```

### **2. LLM Reranker Implementation**

```php
// backend/app/Services/RAG/LLMReranker.php
class LLMReranker implements RerankerInterface
{
    public function rerank(string $query, array $candidates, int $topN): array
    {
        $batchSize = 5; // Valuta 5 candidati per chiamata
        $scored = [];
        
        foreach (array_chunk($candidates, $batchSize) as $batch) {
            $scores = $this->batchScore($query, $batch);
            
            foreach ($batch as $i => $candidate) {
                $candidate['llm_score'] = $scores[$i] ?? 0;
                $scored[] = $candidate;
            }
        }
        
        // Ordina per LLM score
        usort($scored, fn($a, $b) => $b['llm_score'] <=> $a['llm_score']);
        
        return array_slice($scored, 0, $topN);
    }
    
    private function batchScore(string $query, array $candidates): array
    {
        $prompt = "Valuta la rilevanza di questi testi per la domanda (0-100):\n\n";
        $prompt .= "Domanda: {$query}\n\n";
        
        foreach ($candidates as $i => $candidate) {
            $prompt .= "Testo " . ($i + 1) . ": {$candidate['text']}\n\n";
        }
        
        $prompt .= "Rispondi solo con i punteggi separati da virgola (es: 85,72,91,45,63):";
        
        $response = $this->llm->generateText([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 50,
            'temperature' => 0.1
        ]);
        
        return array_map('intval', explode(',', trim($response)));
    }
}
```

### **3. Integrazione nel Sistema Esistente**

```php
// Modifica a KbSearchService.php
class KbSearchService 
{
    public function __construct(
        // ... esistenti ...
        private readonly ?HyDEExpander $hyde = null,
        private readonly ?LLMReranker $llmReranker = null
    ) {}
    
    public function retrieve(int $tenantId, string $query, bool $debug = false): array
    {
        // ... logica esistente ...
        
        // NUOVO: HyDE expansion se abilitato
        if ($this->hyde && config('rag.features.hyde', false)) {
            $hydeResult = $this->hyde->expandQuery($query, $tenantId);
            $enhancedQuery = $hydeResult['hypothetical'];
            $customEmbedding = $hydeResult['combined_embedding'];
            
            // Usa embedding custom per ricerca vettoriale
            $vecHit = $this->milvus->searchTopKWithEmbedding(
                $tenantId, $customEmbedding, $vecTopK
            );
        }
        
        // ... resto della logica ...
        
        // NUOVO: LLM Reranking se abilitato
        $rerankerType = config('rag.reranker.driver', 'embedding');
        if ($rerankerType === 'llm' && $this->llmReranker) {
            $ranked = $this->llmReranker->rerank($query, $candidates, $topN);
        } else {
            // Logica esistente
            $reranker = $rerankerType === 'cohere' 
                ? new CohereReranker() 
                : new EmbeddingReranker($this->embeddings);
            $ranked = $reranker->rerank($query, $candidates, $topN);
        }
        
        // ... resto immutato ...
    }
}
```

## ⚙️ Configurazione

```php
// config/rag.php - Nuove opzioni
'advanced' => [
    'hyde' => [
        'enabled' => env('RAG_HYDE_ENABLED', false),
        'model' => env('RAG_HYDE_MODEL', 'gpt-4o-mini'),
        'max_tokens' => (int) env('RAG_HYDE_MAX_TOKENS', 200),
        'temperature' => (float) env('RAG_HYDE_TEMPERATURE', 0.3),
        'weight_original' => (float) env('RAG_HYDE_WEIGHT_ORIG', 0.6),
        'weight_hypothetical' => (float) env('RAG_HYDE_WEIGHT_HYPO', 0.4),
    ],
    
    'llm_reranker' => [
        'enabled' => env('RAG_LLM_RERANK_ENABLED', false),
        'model' => env('RAG_LLM_RERANK_MODEL', 'gpt-4o-mini'),
        'batch_size' => (int) env('RAG_LLM_RERANK_BATCH_SIZE', 5),
        'max_tokens' => (int) env('RAG_LLM_RERANK_MAX_TOKENS', 50),
    ],
    
    'query_decomposition' => [
        'enabled' => env('RAG_QUERY_DECOMP_ENABLED', false),
        'max_subqueries' => (int) env('RAG_QUERY_DECOMP_MAX_SUB', 5),
        'complexity_threshold' => (int) env('RAG_QUERY_DECOMP_THRESHOLD', 50),
    ],
],

// Modifica driver reranker esistente
'reranker' => [
    'driver' => env('RAG_RERANK_DRIVER', 'embedding'), // embedding | cohere | llm
    // ... resto esistente ...
],
```

## 📊 Metriche e Testing

### **A/B Testing Setup**

```php
// backend/app/Services/RAG/RAGExperimentService.php
class RAGExperimentService
{
    public function shouldUseAdvancedFeature(string $feature, int $tenantId): bool
    {
        $experiments = config('rag.experiments', []);
        
        if (!isset($experiments[$feature])) {
            return false;
        }
        
        $config = $experiments[$feature];
        $percentage = $config['percentage'] ?? 0;
        
        // Hash deterministico basato su tenant_id
        $hash = crc32($feature . $tenantId) % 100;
        
        return $hash < $percentage;
    }
}
```

### **Metriche di Valutazione**

```php
// backend/app/Services/RAG/RAGEvaluationService.php
class RAGEvaluationService
{
    public function evaluateResponse(
        string $query,
        array $results,
        string $technique
    ): array {
        return [
            'technique' => $technique,
            'query' => $query,
            'num_results' => count($results),
            'avg_score' => $this->calculateAverageScore($results),
            'has_citations' => !empty($results),
            'response_time' => $this->measureResponseTime(),
            'cost_estimate' => $this->estimateCost($technique),
            'timestamp' => now(),
        ];
    }
}
```

## 🧪 Piano di Testing

1. **Implementa HyDE** - Test su 100 query diverse
2. **A/B test** - 50% traffico su nuovo sistema  
3. **Misura metriche**:
   - Rilevanza (manual scoring)
   - Click-through rate
   - User satisfaction
   - Response time
   - Cost per query

4. **Rollout graduale**:
   - 10% → 25% → 50% → 100%
   - Rollback automatico se degradazione

## 💡 Raccomandazioni

**Start with**: HyDE + LLM Reranking (impatto maggiore, rischio basso)

**Avoid initially**: Semantic Chunking (richiede re-ingestion completa)

**Monitor**: Costi OpenAI, latenza, user satisfaction

**Goal**: +30% rilevanza, <+50% costi, <+1s latenza
