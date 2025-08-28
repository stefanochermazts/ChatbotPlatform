# 👥 Gestione Clienti (Tenant) - Documentazione Funzionalità

## 📋 Panoramica
Il sistema di gestione clienti (tenant) di ChatbotPlatform fornisce isolamento multitenant completo, configurazioni RAG personalizzabili per cliente, gestione API keys, e amministrazione completa delle funzionalità per ogni tenant.

---

## 🏗️ Architettura Multitenant

### **Controllers e Services**
- **`TenantAdminController`**: CRUD e gestione base tenant
- **`TenantRagConfigController`**: Configurazioni RAG specifiche
- **`TenantRagConfigService`**: Service layer configurazioni
- **`KnowledgeBase`**: Modello KB per tenant
- **`ApiKey`**: Gestione chiavi API

### **Database Schema**
```sql
-- Tabella tenant principale
tenants:
  id, name, slug, domain, plan, languages, default_language,
  custom_system_prompt, custom_context_template, extra_intent_keywords,
  custom_synonyms, multi_kb_search, rag_settings (JSON), rag_profile,
  created_at, updated_at

-- Chiavi API per tenant  
api_keys:
  id, tenant_id, name, key_hash, scopes, expires_at, created_at

-- Knowledge bases per tenant
knowledge_bases:
  id, tenant_id, name, description, is_default, created_at, updated_at
```

---

## 🏢 Gestione Tenant Base

### **🔧 1. Creazione Tenant**

**Form Creazione:**
```php
$request->validate([
    'name' => 'required|string|max:255',
    'slug' => 'required|string|max:255|unique:tenants,slug',
    'domain' => 'nullable|string|max:255',      // CNAME custom
    'plan' => 'nullable|string|max:64',         // Piano sottoscrizione
    'languages' => 'nullable|string',           // Lingue supportate
    'default_language' => 'nullable|string|max:10',
    'custom_system_prompt' => 'nullable|string|max:4000',
    'custom_context_template' => 'nullable|string|max:2000'
]);
```

**Auto-Setup:**
```php
public function store(Request $request)
{
    $tenant = Tenant::create($data);
    
    // 1. Crea partizione Milvus dedicata
    CreateMilvusPartitionJob::dispatch($tenant->id);
    
    // 2. Genera API key default
    $tenant->generateApiKey('widget-key');
    
    // 3. Crea KB default
    KnowledgeBase::create([
        'tenant_id' => $tenant->id,
        'name' => 'Default',
        'is_default' => true
    ]);
    
    // 4. Setup configurazione RAG default
    $tenant->initializeDefaultRagConfig();
}
```

### **⚙️ 2. Configurazione Base**

**Personalizzazione Linguistica:**
```php
// Multi-language support
'languages' => ['it', 'en', 'fr']           // Lingue supportate
'default_language' => 'it'                  // Lingua default

// Custom prompts
'custom_system_prompt' => '...'             // System prompt personalizzato
'custom_context_template' => '...'          // Template contesto custom
```

**Intent e Sinonimi Custom:**
```php
// Keywords extra per intent detection
'extra_intent_keywords' => [
    'phone' => ['telefono', 'numero', 'contatti'],
    'email' => ['mail', 'posta', 'contatto'],
    'address' => ['indirizzo', 'sede', 'ubicazione']
]

// Sinonimi personalizzati per espansione query
'custom_synonyms' => [
    'documento' => ['certificato', 'attestato', 'carta'],
    'ufficio' => ['sede', 'sportello', 'centro']
]
```

---

## 🧠 Configurazioni RAG Avanzate

### **🎛️ 1. RAG Config Dashboard**

**Interfaccia Admin (`/admin/tenants/{id}/rag-config`):**

**Sezioni Configurazione:**
1. **Hybrid Search**: Vector/BM25 parameters
2. **Answer Thresholds**: Confidence e fallback
3. **Reranking**: Driver e configurazioni
4. **Context Building**: Gestione token e compressione
5. **HyDE**: Query expansion avanzata
6. **LLM Reranker**: Reranking intelligente
7. **Intent Detection**: Configurazione intent
8. **KB Selection**: Modalità selezione knowledge base

### **📊 2. Parametri RAG Configurabili**

**Hybrid Search:**
```php
'hybrid' => [
    'vector_top_k' => 40,           // Risultati ricerca vettoriale
    'bm25_top_k' => 80,            // Risultati ricerca BM25
    'mmr_take' => 10,              // Chunk finali dopo MMR
    'mmr_lambda' => 0.6,           // Bilanciamento rilevanza/diversità
    'neighbor_radius' => 2,        // Chunk adiacenti inclusi
    'rrf_k' => 60                  // Parametro Reciprocal Rank Fusion
]
```

**Answer Thresholds:**
```php
'answer' => [
    'min_citations' => 1,          // Citazioni minime per risposta
    'min_confidence' => 0.3,       // Confidence minima
    'force_if_has_citations' => true, // Forza risposta se ha citazioni
    'fallback_message' => 'Non lo so' // Messaggio fallback
]
```

**Reranking Configuration:**
```php
'reranker' => [
    'driver' => 'embedding',       // embedding|llm|cohere|none
    'top_n' => 40,                 // Candidati per reranking
    'enabled' => true,
    'cohere_api_key' => '...'      // Se driver=cohere
]
```

**Context Building:**
```php
'context' => [
    'max_chars' => 6000,           // Max caratteri contesto LLM
    'compress_if_over_chars' => 7000, // Soglia compressione
    'compress_target_chars' => 3500,  // Target dopo compressione
    'include_chunk_metadata' => true
]
```

### **🚀 3. Profili Predefiniti**

**Template Configurazioni:**
```php
// Profili disponibili
$availableProfiles = [
    'public_administration' => [
        'hybrid' => ['vector_top_k' => 50, 'bm25_top_k' => 100],
        'answer' => ['min_confidence' => 0.4],
        'reranker' => ['driver' => 'llm']  // Accuracy alta
    ],
    'ecommerce' => [
        'hybrid' => ['vector_top_k' => 30, 'mmr_lambda' => 0.8],
        'answer' => ['min_confidence' => 0.2],
        'reranker' => ['driver' => 'embedding']  // Velocità alta
    ],
    'customer_service' => [
        'hybrid' => ['vector_top_k' => 40, 'neighbor_radius' => 3],
        'answer' => ['min_confidence' => 0.3],
        'context' => ['max_chars' => 8000]
    ]
];
```

**Applicazione Profilo:**
```php
public function applyProfile(Tenant $tenant, string $profile): void
{
    $template = $this->getProfileTemplate($profile);
    $currentConfig = $tenant->rag_settings ?? [];
    
    // Merge template con configurazioni esistenti
    $newConfig = array_merge_recursive($currentConfig, $template);
    
    $tenant->update([
        'rag_profile' => $profile,
        'rag_settings' => $newConfig
    ]);
}
```

---

## 🔑 Gestione API Keys

### **🛡️ 1. API Key Security**

**Generazione Sicura:**
```php
public function generateApiKey(string $name = 'default'): ApiKey
{
    $key = 'cb_' . Str::random(40);  // Prefisso identificativo
    
    return ApiKey::create([
        'tenant_id' => $this->id,
        'name' => $name,
        'key_hash' => hash('sha256', $key),  // Solo hash salvato
        'scopes' => ['chat:completions'],    // Scope limitati
        'expires_at' => now()->addYear()     // Scadenza
    ]);
}
```

**Autenticazione Request:**
```php
// Middleware ApiKeyAuth
public function handle(Request $request, Closure $next)
{
    $apiKey = $request->bearerToken();
    
    if (!$apiKey || !str_starts_with($apiKey, 'cb_')) {
        return response()->json(['error' => 'Invalid API key'], 401);
    }
    
    $keyRecord = ApiKey::where('key_hash', hash('sha256', $apiKey))
                       ->where('expires_at', '>', now())
                       ->first();
                       
    if (!$keyRecord) {
        return response()->json(['error' => 'API key not found'], 401);
    }
    
    // Inject tenant_id in request
    $request->attributes->set('tenant_id', $keyRecord->tenant_id);
    
    return $next($request);
}
```

### **🔄 2. API Key Management**

**Rotazione Keys:**
```php
// Genera nuova key mantenendo la vecchia per transizione
public function rotateApiKey(string $name = 'default'): ApiKey
{
    // Genera nuova key
    $newKey = $this->generateApiKey($name . '_new');
    
    // Programma scadenza vecchia key (grace period)
    $oldKey = $this->apiKeys()->where('name', $name)->first();
    if ($oldKey) {
        $oldKey->update(['expires_at' => now()->addDays(7)]);
    }
    
    return $newKey;
}
```

**Scopes e Permessi:**
```php
'scopes' => [
    'chat:completions',        // Accesso chat endpoint
    'documents:read',          // Lettura documenti (future)
    'analytics:read'           // Lettura analytics (future)
]
```

---

## 🗂️ Knowledge Base Management

### **📚 1. Multi-KB per Tenant**

**Organizzazione KB:**
```php
// Ogni tenant può avere multiple KB
knowledge_bases:
  - id: 1, tenant_id: 5, name: "Servizi Pubblici", is_default: true
  - id: 2, tenant_id: 5, name: "FAQ Cittadini", is_default: false
  - id: 3, tenant_id: 5, name: "Normative", is_default: false
```

**Selezione Automatica KB:**
```php
public function selectForQuery(string $query, int $tenantId): ?int
{
    // 1. Normalizza query per KB selection  
    $normalizedQuery = $this->normalizeQueryForKbSelection($query);
    
    // 2. BM25 search su tutte le KB del tenant
    $results = $this->searchAcrossKBs($normalizedQuery, $tenantId);
    
    // 3. Selezione KB con score più alto
    $bestKb = $this->selectBestKB($results);
    
    // 4. Validazione: KB selezionata ha documenti?
    return $this->validateKbHasDocuments($bestKb, $tenantId);
}
```

### **🔀 2. Multi-KB Search Mode**

**Configurazione:**
```php
// Flag tenant per ricerca cross-KB
$tenant->multi_kb_search = true;

// Logica di ricerca
if ($tenant->multi_kb_search) {
    // Cerca in TUTTE le KB del tenant
    $selectedKbId = null;  // null = tutte
    $knowledgeBases = $this->getAllKnowledgeBasesForTenant($tenantId);
} else {
    // Selezione KB automatica standard
    $selectedKbId = $this->kbSelector->selectForQuery($query, $tenantId);
}
```

---

## 🎨 Personalizzazione UI/UX

### **🖼️ 1. Branding per Tenant**

**Widget Personalizzazione:**
- Logo custom nel widget
- Palette colori personalizzata  
- Font family custom
- Messaggi di benvenuto personalizzati
- Tema completo custom

**Admin Dashboard:**
- Logo tenant nell'header admin
- Colori tema admin personalizzabili
- Footer custom con info tenant

### **🌍 2. Multi-Language Support**

**Configurazione Lingue:**
```php
// Tenant multi-lingua
'languages' => ['it', 'en', 'fr']
'default_language' => 'it'

// Auto-detection lingua da query
$detectedLang = $this->detectLanguage($query);
if (!in_array($detectedLang, $tenant->languages)) {
    $detectedLang = $tenant->default_language;
}
```

**Prompt Localizzazione:**
```php
// System prompt localizzato
$systemPrompts = [
    'it' => 'Rispondi solo in italiano usando le informazioni fornite...',
    'en' => 'Answer only in English using the provided information...',
    'fr' => 'Répondez uniquement en français en utilisant les informations fournies...'
];

$prompt = $systemPrompts[$detectedLang] ?? $systemPrompts[$tenant->default_language];
```

---

## 📊 Analytics e Monitoring

### **📈 1. Tenant Metrics Dashboard**

**KPI per Tenant:**
```php
// Conversazioni e utilizzo
$metrics = [
    'total_conversations' => 1250,
    'messages_per_session' => 4.2,
    'bounce_rate' => 0.15,
    'avg_response_time' => '1.2s',
    'user_satisfaction' => 4.3,     // Rating medio
    'cost_per_conversation' => 0.05  // $ per conversazione
];

// RAG Performance
$ragMetrics = [
    'citations_found_rate' => 0.87,    // % query con citazioni
    'confidence_avg' => 0.72,          // Confidence media
    'fallback_rate' => 0.13,           // % "Non lo so"
    'kb_coverage' => 0.65               // % KB utilizzata
];
```

### **💰 2. Cost Management**

**Budget e Limiti:**
```php
// Piano e limiti tenant
$tenant->plan = 'premium';
$limits = [
    'monthly_messages' => 10000,
    'monthly_cost_limit' => 500.00,    // $ max per mese
    'concurrent_users' => 100,
    'storage_gb' => 50
];

// Monitoring utilizzo real-time
$usage = [
    'messages_this_month' => 7842,
    'cost_this_month' => 234.50,
    'storage_used_gb' => 23.4,
    'peak_concurrent_users' => 67
];
```

**Alert e Notifiche:**
```php
// Alert configurabili
if ($usage['cost_this_month'] > $limits['monthly_cost_limit'] * 0.8) {
    $this->sendCostAlert($tenant, 'approaching_limit');
}

if ($usage['messages_this_month'] > $limits['monthly_messages']) {
    $this->throttleRequests($tenant);
}
```

---

## 🔧 Configurazioni Avanzate

### **⚡ 1. Performance Tuning**

**Cache Configurations:**
```php
// Cache configurazioni RAG per tenant
Cache::remember("tenant_rag_config_{$tenantId}", 3600, function() use ($tenantId) {
    return $this->loadTenantRagConfig($tenantId);
});

// Cache risultati query frequenti
Cache::remember("rag_results_{$tenantId}_{$queryHash}", 1800, function() {
    return $this->performRagSearch($query, $tenantId);
});
```

**Queue Prioritization:**
```php
// Priorità job per tenant premium
if ($tenant->plan === 'premium') {
    IngestUploadedDocumentJob::dispatch($documentId)
                            ->onQueue('ingestion_priority');
} else {
    IngestUploadedDocumentJob::dispatch($documentId)
                            ->onQueue('ingestion_standard');
}
```

### **🛡️ 2. Security e Compliance**

**Data Isolation:**
```php
// Query scoping automatico
Document::where('tenant_id', $tenantId)  // Sempre required
Chat::where('tenant_id', $tenantId)      // Sempre required
// Global scope su tutti i modelli per sicurezza
```

**GDPR Compliance:**
```php
// Right to be forgotten
public function deleteAllTenantData(int $tenantId): void
{
    DB::transaction(function() use ($tenantId) {
        // 1. Delete da Milvus
        $this->milvus->deletePartition($tenantId);
        
        // 2. Delete documenti e chunks
        Document::where('tenant_id', $tenantId)->delete();
        
        // 3. Delete conversazioni
        Chat::where('tenant_id', $tenantId)->delete();
        
        // 4. Delete configurazioni
        $tenant = Tenant::find($tenantId);
        $tenant->delete();
    });
}
```

---

## 📁 File Critici

```
backend/
├── app/Http/Controllers/Admin/
│   ├── TenantAdminController.php           # CRUD tenant
│   └── TenantRagConfigController.php       # Configurazioni RAG
├── app/Services/RAG/
│   └── TenantRagConfigService.php          # Service configurazioni
├── app/Models/
│   ├── Tenant.php                         # Model principale
│   ├── ApiKey.php                         # Gestione API keys
│   └── KnowledgeBase.php                  # KB per tenant
├── resources/views/admin/tenants/
│   ├── index.blade.php                    # Lista tenant
│   ├── edit.blade.php                     # Modifica tenant
│   └── rag-config.blade.php               # Configurazione RAG
├── config/
│   └── rag-tenant-defaults.php            # Default RAG config
└── database/migrations/
    └── add_rag_settings_to_tenants_table.php
```

---

## 🚨 Troubleshooting

### **Problemi Comuni**

**1. Tenant Isolation Breach**
```bash
✅ Check: Tutti i model hanno tenant_id scope
✅ Check: Query sempre filtrate per tenant_id
✅ Check: API keys correttamente validate
✅ Check: Milvus partition isolation attiva
```

**2. RAG Config Non Applicata**
```bash
⚙️ Check: TenantRagConfigService carica config corretta
⚙️ Check: Cache configuration aggiornata
⚙️ Check: Override temporaneo non interferisce
⚙️ Debug: Log della config effettivamente usata
```

**3. Performance Degradation**
```bash
🔧 Check: Cache hit rate configurazioni
🔧 Check: Queue worker capacity
🔧 Check: Milvus partition performance
🔧 Monitor: Cost per tenant trends
```

**4. API Key Issues**
```bash
🔑 Check: Key format corretto (cb_prefixed)
🔑 Check: Hash match in database
🔑 Check: Scadenza key non superata
🔑 Check: Scopes appropriati per endpoint
```

### **Debug Tenant Config**
```bash
# Check configurazione tenant
php artisan tinker --execute="
\$tenant = App\Models\Tenant::find(5);
\$config = app(App\Services\RAG\TenantRagConfigService::class);
dump(\$config->getConfig(\$tenant->id));
"

# Test API key tenant
curl -H "Authorization: Bearer cb_your_api_key" \
     http://localhost:8000/api/v1/chat/completions \
     -d '{"model":"gpt-4o-mini","messages":[...]}'
```

---

## 📊 Success Metrics

### **Business KPIs**
- **Tenant Retention**: % tenant attivi dopo 6 mesi
- **Revenue per Tenant**: $ medio per tenant/mese
- **Feature Adoption**: % tenant che usano feature avanzate
- **Support Ticket Rate**: Ticket per tenant/mese

### **Technical KPIs**  
- **API Uptime**: 99.9% SLA per tenant
- **Response Time**: P95 < 2.5s per tenant
- **Error Rate**: < 0.1% per tenant
- **Data Isolation**: 100% (zero breaches)

### **User Experience KPIs**
- **Onboarding Time**: Tempo setup nuovo tenant
- **Configuration Accuracy**: % config tenant corrette
- **Self-Service Rate**: % tenant che configurano autonomamente
- **Satisfaction Score**: Rating medio tenant experience
