# 🧠 RAG Tester - Documentazione Funzionalità

## 📋 Panoramica
Il RAG Tester è un tool avanzato per testare, debuggare e ottimizzare il sistema di Retrieval-Augmented Generation. Permette di testare query, analizzare risultati e confrontare configurazioni RAG in tempo reale.

---

## 🏗️ Architettura Sistema

### **Controller e Services**
- **`RagTestController`**: Controller principale per testing
- **`KbSearchService`**: Orchestratore retrieval
- **`MilvusClient`**: Ricerca vettoriale 
- **`OpenAIChatService`**: Generazione risposte LLM
- **`HyDEExpander`**: Espansione query ipotetica
- **`ConversationContextEnhancer`**: Contesto conversazionale

### **Integrazione con Configurazioni Tenant**
- Utilizza configurazioni RAG specifiche per tenant
- Override temporaneo parametri per testing
- Ripristino automatico configurazioni originali

---

## ⚙️ Funzionalità Testing

### **🔍 1. Testing Query Base**

**Interfaccia di Test:**
```php
// Parametri richiesti
'tenant_id' => 'required|integer|exists:tenants,id'
'query' => 'required|string'

// Parametri opzionali
'with_answer' => 'boolean'           // Genera risposta LLM completa
'enable_hyde' => 'boolean'           // Attiva HyDE expansion  
'enable_conversation' => 'boolean'   // Contesto conversazionale
'conversation_messages' => 'string'  // Messaggi conversazione JSON
'reranker_driver' => 'embedding|llm|cohere'
'top_k' => 'integer|min:1|max:50'
'mmr_lambda' => 'numeric|min:0|max:1'
'max_output_tokens' => 'integer|min:32|max:8192'
```

### **🧪 2. Configurazioni Sperimentali**

**HyDE (Hypothetical Document Embeddings):**
```php
// Espansione query con documento ipotetico
Config::set('rag.advanced.hyde.enabled', true);
$hyde = app(HyDEExpander::class);
$enhancedQuery = $hyde->expandQuery($originalQuery);
```

**Reranking Drivers:**
- **`embedding`**: Reranking basato su similarity embeddings
- **`llm`**: Reranking intelligente via LLM (più lento, più accurato)
- **`cohere`**: Reranking via Cohere API (richiede API key)

**Conversation Enhancement:**
```php
// Arricchimento query con contesto conversazionale
$conversationEnhancer = app(ConversationContextEnhancer::class);
$context = $conversationEnhancer->enhanceQuery($query, $messages, $tenantId);
$enhancedQuery = $context['enhanced_query'];
```

### **📊 3. Output e Debug Dettagliato**

**Risultati Retrieval:**
```php
$retrieval = [
    'citations' => [
        [
            'id' => 'document_id',
            'title' => 'Document Title', 
            'snippet' => 'Relevant text extract',
            'chunk_text' => 'Full chunk content',  // Nuovo: chunk completo
            'score' => 0.85,
            'chunk_index' => 2,
            'source_url' => 'https://...',
            'phone' => '+39...',      // Intent extraction
            'email' => 'info@...',    // Intent extraction
            'address' => '...',       // Intent extraction
            'schedule' => '9:00-17:00' // Intent extraction
        ]
    ],
    'confidence' => 0.85,
    'debug' => [...]  // Trace completo
];
```

**Debug Trace:**
```php
$debug = [
    'vector_hits' => [...],           // Risultati ricerca vettoriale
    'bm25_hits' => [...],            // Risultati ricerca BM25
    'fused_top' => [...],            // Risultati dopo RRF fusion
    'reranked' => [...],             // Dopo reranking
    'mmr_final' => [...],            // Dopo MMR diversification
    'conversation' => [...],         // Context conversazionale
    'llm_context' => '...',          // Context inviato a LLM
    'llm_messages' => [...],         // Messaggi completi LLM
    'tenant_prompts' => [...]        // Prompt personalizzati tenant
];
```

---

## 🎛️ Interfaccia Web

### **Dashboard RAG Tester (`/admin/rag`)**

**Form di Testing:**
1. **Selezione Tenant**: Dropdown tenant disponibili
2. **Query Input**: Campo testo per query di test
3. **Configurazioni Avanzate**:
   - ☐ Genera Risposta Completa (`with_answer`)
   - ☐ Attiva HyDE (`enable_hyde`) 
   - ☐ Contesto Conversazionale (`enable_conversation`)
   - Reranker: `embedding` | `llm` | `cohere`
   - Top K: `1-50`
   - MMR Lambda: `0.0-1.0`
   - Max Output Tokens: `32-8192`

**Risultati Display:**
```html
<!-- Sezione Salute Sistema -->
<div class="health-status">
  Milvus: ✅ Connected | Tenant Partition: ✅ Active
</div>

<!-- Risultati Retrieval -->
<div class="citations">
  <h3>📄 Citations Found: 5 | Confidence: 0.85</h3>
  
  <div class="citation">
    <h4>📋 Doc #1234: Document Title</h4>
    <div class="scores">Vector: 0.89 | BM25: 0.73 | Final: 0.85</div>
    
    <!-- Snippet breve -->
    <blockquote class="snippet">Estratto rilevante...</blockquote>
    
    <!-- NUOVO: Chunk completo se diverso -->
    <blockquote class="chunk-full">Contenuto chunk completo...</blockquote>
    
    <!-- Intent data se disponibile -->
    <div class="intent-data">
      📞 +39... | 📧 info@... | 📍 Via... | 🕐 9:00-17:00
    </div>
  </div>
</div>

<!-- Risposta LLM (se richiesta) -->
<div class="llm-answer">
  <h3>🤖 Risposta Generata</h3>
  <div class="answer-text">...</div>
  <div class="source-link">🔗 Fonte principale: https://...</div>
</div>

<!-- Debug Trace -->
<details class="debug-trace">
  <summary>🔍 Debug Info</summary>
  <pre>JSON trace completo...</pre>
</details>
```

---

## 🔧 Configurazioni Override

### **Override Temporaneo Parametri**
Il RAG Tester può sovrascrivere temporaneamente le configurazioni tenant:

```php
// Backup configurazioni originali
$originalHydeConfig = config('rag.advanced.hyde.enabled');
$originalRerankerDriver = config('rag.reranker.driver');

try {
    // Applica configurazioni test
    Config::set('rag.advanced.hyde.enabled', $testHydeEnabled);
    Config::set('rag.reranker.driver', $testRerankerDriver);
    
    // Esegui test
    $results = $kb->retrieve($tenantId, $query, true);
    
} finally {
    // Ripristina configurazioni originali
    Config::set('rag.advanced.hyde.enabled', $originalHydeConfig);
    Config::set('rag.reranker.driver', $originalRerankerDriver);
}
```

### **Logging Avanzato**
```php
// Log configurazioni applicate
Log::info('RagTestController RAG Config', [
    'tenant_id' => $tenantId,
    'original_query' => $query,
    'final_query' => $enhancedQuery,
    'conversation_enhanced' => $conversationUsed,
    'hyde_enabled' => config('rag.advanced.hyde.enabled'),
    'reranker_driver' => config('rag.reranker.driver'),
    'with_answer' => $generateAnswer,
    'caller' => 'RagTestController'
]);
```

---

## 📈 Analisi Performance

### **Metriche Visualizzate**

**Retrieval Metrics:**
- Numero citations trovate
- Confidence score complessivo
- Tempi di risposta per fase:
  - Vector search: ~50ms
  - BM25 search: ~30ms  
  - Reranking: ~100ms (embedding) / ~800ms (LLM)
  - Total: ~200ms-1000ms

**Quality Metrics:**
- Distribution score per citation
- Coverage knowledge base
- Intent detection accuracy
- Fallback rate ("Non lo so")

### **Confronto Configurazioni**
Possibilità di testare stessa query con configurazioni diverse:

| Config | Citations | Confidence | Response Time | Quality |
|--------|-----------|------------|---------------|---------|
| Standard | 5 | 0.85 | 200ms | ⭐⭐⭐⭐ |
| + HyDE | 7 | 0.91 | 350ms | ⭐⭐⭐⭐⭐ |
| + LLM Rerank | 5 | 0.93 | 800ms | ⭐⭐⭐⭐⭐ |

---

## 🚀 Casi d'Uso Avanzati

### **1. Debug KB Vuota**
```bash
# Test salute sistema
Query: "test" → Citations: 0 → Check Milvus partition

# Verifica indicizzazione
Query: "documento test" → Citations: 0 → Check ingestion status
```

### **2. Ottimizzazione Parameters**
```bash
# Test variazioni MMR Lambda
λ=0.0: Focus diversità → 8 docs diversi, confidence bassa
λ=0.5: Bilanciato → 5 docs rilevanti, confidence media  
λ=1.0: Focus rilevanza → 3 docs molto rilevanti, confidence alta
```

### **3. Test Intent Specializzati**
```bash
# Query telefono
"numero ufficio" → Intent: phone → Extraction: +39...

# Query orario  
"quando aperto" → Intent: schedule → Extraction: 9:00-17:00

# Query generica
"info servizi" → Intent: none → Semantic search
```

### **4. Conversation Testing**
```json
{
  "messages": [
    {"role": "user", "content": "Ciao, info su documenti"},
    {"role": "assistant", "content": "Posso aiutarti con documenti..."},
    {"role": "user", "content": "quelli per residenza"}
  ]
}
```
→ Enhanced query: "documenti per residenza anagrafe certificati"

---

## 🔗 Integrazione con Sistema RAG

### **Sincronizzazione con Widget**
Il RAG Tester utilizza la **stessa pipeline** del widget:
- Stessi servizi (`KbSearchService`, `MilvusClient`)
- Stesse configurazioni tenant
- Stesso endpoint LLM (`OpenAIChatService`)
- Stessa logica intent detection

### **Differenze Configurazioni**
```php
// RAG Tester: Configurazioni aggressive per testing
Config::set('rag.hybrid.vector_top_k', 50);      // Più risultati
Config::set('rag.reranker.driver', 'llm');       // Reranking migliore
Config::set('rag.advanced.hyde.enabled', true);  // HyDE attivo

// Widget: Configurazioni bilanciate per velocità  
Config::set('rag.hybrid.vector_top_k', 30);      // Performance
Config::set('rag.reranker.driver', 'embedding'); // Velocità
Config::set('rag.advanced.hyde.enabled', false); // No timeout
```

---

## 📁 File Critici

```
backend/
├── app/Http/Controllers/Admin/
│   └── RagTestController.php              # Controller principale
├── app/Services/RAG/
│   ├── KbSearchService.php               # Orchestratore retrieval
│   ├── HyDEExpander.php                  # Query expansion
│   ├── ConversationContextEnhancer.php  # Context conversazionale
│   ├── MilvusClient.php                  # Vector search
│   └── TextSearchService.php             # BM25 + intent
├── resources/views/admin/rag/
│   └── index.blade.php                   # UI testing
└── routes/web.php                        # Route admin/rag
```

---

## 🚨 Troubleshooting

### **Problemi Comuni**

**1. Citations: 0**
```bash
✅ Check: Milvus health status
✅ Check: Tenant partition exists  
✅ Check: Documents ingested for tenant
✅ Check: Embeddings generated
```

**2. Low Confidence (<0.3)**
```bash
⚙️ Solution: Aumenta vector_top_k
⚙️ Solution: Prova HyDE expansion
⚙️ Solution: Verifica query phrasing
⚙️ Solution: Check document quality
```

**3. Timeout con LLM Reranker**
```bash
⚙️ Solution: Riduci reranker_top_n
⚙️ Solution: Usa embedding reranker
⚙️ Solution: Aumenta timeout OpenAI
```

**4. "Non lo so" con Citations > 0**
```bash
⚙️ Check: min_confidence troppo alta
⚙️ Check: min_citations configurazione
⚙️ Check: Context building corretto
⚙️ Check: LLM prompt template
```

### **Debug Logging**
```bash
# Attiva logging dettagliato
tail -f storage/logs/laravel.log | grep "RagTestController\|KbSearchService"

# Trace Milvus queries
tail -f storage/logs/laravel.log | grep "milvus_search\|vector_search"
```

---

## 📊 Metriche e KPI

### **Testing Performance**
- **Accuracy**: % query con citations rilevanti
- **Coverage**: % KB coperta da test queries  
- **Latency**: P95 < 2.5s per risposta completa
- **Consistency**: Stessi risultati su query ripetute

### **Quality Assurance**
- **Groundedness**: Citations supportano risposta
- **Completeness**: Risposta copre tutti aspetti query
- **Relevance**: Citations pertinenti a query
- **Freshness**: Documenti aggiornati prioritizzati





