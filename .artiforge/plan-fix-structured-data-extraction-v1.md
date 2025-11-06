# Piano: Fix Structured Data Extraction - Doc 4350

**Created**: 2025-10-20  
**Priority**: HIGH  
**Estimated Time**: 2-3 hours  
**Status**: Ready for execution

---

## 🎯 Obiettivo

Risolvere il problema dell'associazione tra numeri di telefono e servizi comunali nel documento 4350 ("Orari e contatti degli uffici"), permettendo all'LLM di rispondere correttamente a query come "telefono comando polizia locale" con "06.95898223" invece di "113".

---

## 🔍 Root Cause Analysis

### Problema Attuale
1. **Chunk Structure**: Il chunk 2 di doc 4350 contiene:
   ```
   06.95898223
   
   Orari apertura al pubblico Ats
   ...
   **SETTORE VII** – Polizia Locale e Protezione Civile
   ```

2. **LLM Confusion**: L'LLM vede:
   - Tabella nei doc 4304/4315: `| Polizia di Stato | - | 113 |` (più esplicita)
   - Nel doc 4350: numero `06.95898223` all'inizio, "Polizia Locale" 200+ caratteri dopo
   - **Non riesce a inferire l'associazione** → sceglie 113

3. **Citation Ranking**: Doc 4350 è #1 nel fusion RRF ma appare #3 nella presentazione finale

### Evidenze dal Web Search
Dalla pagina reale (https://www.comune.sancesareo.rm.it/zf/index.php/servizi-aggiuntivi/index/index/idtesto/20110):
```html
**COMANDO POLIZIA LOCALE:** 
**email: polizialocale@comune.sancesareo.rm.it**  
tel:06.95898223.  
Orari apertura al pubblico Comando Polizia Locale
```

Il formato originale è **molto chiaro**, ma il chunking lo separa.

---

## 📋 Piano di Implementazione

### **Step 1**: Analisi Dettagliata del Chunk Attuale ✅

**Obiettivo**: Verificare esattamente come il chunk è strutturato

**Actions**:
1. ✅ Query database per chunk 2 di doc 4350
2. ✅ Identificare la posizione del numero "06.95898223"
3. ✅ Identificare la posizione della label "Comando Polizia Locale"
4. ✅ Misurare distanza in caratteri tra i due

**Risultato**:
- Numero all'inizio (posizione ~0-15)
- Label "SETTORE VII - Polizia Locale" a posizione ~300+
- Distanza: >300 caratteri
- **Conclusione**: Associazione implicita troppo debole per l'LLM

---

### **Step 2**: Implementare Structured Data Extraction Service

**Obiettivo**: Creare un servizio per estrarre dati strutturati (telefono, email, orario) dai chunk

**File da creare**: `backend/app/Services/Ingestion/StructuredDataExtractor.php`

**Spec**:
```php
class StructuredDataExtractor
{
    /**
     * Extract structured data from chunk content
     * 
     * @param string $content Chunk text
     * @param array $context Additional context (document metadata, etc.)
     * @return array Structured fields
     */
    public function extract(string $content, array $context = []): array
    {
        return [
            'phones' => $this->extractPhones($content),
            'emails' => $this->extractEmails($content),
            'hours' => $this->extractHours($content),
            'services' => $this->extractServices($content),
            'associations' => $this->associateData($content), // KEY!
        ];
    }

    /**
     * Associate phones/emails with their service labels
     * 
     * Uses proximity-based heuristics and pattern matching
     */
    private function associateData(string $content): array
    {
        // Extract phone numbers with context window (±200 chars)
        // Extract service labels (bold text, headers, "SETTORE X")
        // Match phone -> service by proximity
        // Return structured associations
    }
}
```

**Patterns da riconoscere**:
- Telefoni: `06.XXXXXXXX`, `06 XXXXXXXX`, `tel:XXXXXXXX`, `06/XXXXXXXX`
- Servizi: `**NOME SERVIZIO:**`, `**SETTORE X**`, pattern con "UFFICIO", "COMANDO"
- Associazione: Se telefono appare entro 200 caratteri da label servizio, associali

**Test Case**:
```php
$content = "06.95898223\n\nOrari...\n\n**SETTORE VII** – Polizia Locale";
$extracted = $extractor->extract($content);

// Expected:
[
    'phones' => ['06.95898223'],
    'services' => ['Polizia Locale', 'SETTORE VII'],
    'associations' => [
        [
            'phone' => '06.95898223',
            'service' => 'Polizia Locale (SETTORE VII)',
            'confidence' => 0.85
        ]
    ]
]
```

---

### **Step 3**: Modificare DocumentChunk Model per Structured Fields

**File**: `backend/app/Models/DocumentChunk.php`

**Changes**:
1. Aggiungere cast per `structured_data` JSON field (se non esiste già)
   ```php
   protected $casts = [
       'structured_data' => 'array',
       // ...
   ];
   ```

2. Verificare se colonna `structured_data` esiste in migration
   - Se NO: creare migration `add_structured_data_to_document_chunks`

**Migration** (se necessaria):
```php
Schema::table('document_chunks', function (Blueprint $table) {
    $table->jsonb('structured_data')->nullable()->after('content');
});
```

---

### **Step 4**: Integrare Extraction nel Chunking Pipeline

**File**: `backend/app/Jobs/IngestUploadedDocumentJob.php`

**Changes**:
```php
// After chunking (line ~180)
foreach ($chunks as $index => $chunkText) {
    // ... existing chunk creation ...
    
    // NEW: Extract structured data
    $structuredData = app(StructuredDataExtractor::class)->extract(
        $chunkText,
        [
            'document_id' => $document->id,
            'document_title' => $document->title,
            'chunk_index' => $index,
        ]
    );
    
    $chunk->structured_data = $structuredData;
    $chunk->save();
}
```

---

### **Step 5**: Modificare ContextBuilder per Includere Structured Data

**File**: `backend/app/Services/RAG/ContextBuilder.php`

**Current** (lines 75-80):
```php
$text = $snippet;
if (!empty($structured)) {
    $text .= "\n" . $structured;
}
$text .= "\nTelefono: " . implode(', ', $phones);
```

**NEW**:
```php
$text = $snippet;

// Add structured associations if available
if (!empty($chunk->structured_data['associations'])) {
    $text .= "\n\n📞 Contatti strutturati:";
    foreach ($chunk->structured_data['associations'] as $assoc) {
        $text .= "\n- {$assoc['service']}: {$assoc['phone']}";
    }
}

// Fallback: extract phones from snippet (existing logic)
if (empty($chunk->structured_data['associations']) && !empty($phones)) {
    $text .= "\nTelefono: " . implode(', ', $phones);
}
```

**Esempio Output**:
```
[www.comune.sancesareo.rm.it]
06.95898223

Orari apertura al pubblico Ats
...

📞 Contatti strutturati:
- Polizia Locale (SETTORE VII): 06.95898223
- Ausiliari del Traffico: 06.95898223

[Fonte: https://...]
```

---

### **Step 6**: Re-ingest Doc 4350 con Structured Extraction

**Actions**:
1. Backup chunks attuali (optional)
2. Delete chunks for doc 4350: `DocumentChunk::where('document_id', 4350)->delete();`
3. Re-trigger ingestion job: `IngestUploadedDocumentJob::dispatch($document, ...);`
4. Verify structured_data field populated

**Script**: `backend/reingest_doc_4350_structured.php`
```php
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$doc = \App\Models\Document::find(4350);
if (!$doc) {
    die("Doc 4350 not found\n");
}

echo "🔄 Re-ingesting doc 4350 with structured extraction...\n";

// Delete existing chunks
$deleted = \App\Models\DocumentChunk::where('document_id', 4350)->delete();
echo "   Deleted {$deleted} chunks\n";

// Re-trigger ingestion
$path = storage_path('app/' . $doc->file_path);
\App\Jobs\IngestUploadedDocumentJob::dispatch($doc, $path);

echo "✅ Job dispatched. Run queue:work to process.\n";
```

---

### **Step 7**: System Prompt Enhancement (Optional)

**File**: Database `tenants` table, `custom_system_prompt` for tenant 5

**Current**:
```
Sei un assistente del Comune di San Cesareo. Rispondi usando le informazioni dai passaggi forniti nel contesto. Se il contesto contiene telefoni, email, indirizzi o orari, riportali anche se non sono esplicitamente etichettati con il nome del servizio. Cerca di inferire le informazioni dai dati disponibili. Solo se il contesto NON contiene alcuna informazione rilevante, rispondi: "Non ho trovato informazioni sufficienti nella base di conoscenza".
```

**Enhanced** (optional, se Step 5 non basta):
```
Sei un assistente del Comune di San Cesareo. Rispondi usando le informazioni dai passaggi forniti nel contesto.

PRIORITÀ PER TELEFONI:
- Se vedi sezione "📞 Contatti strutturati:", usa QUEI numeri (sono associati al servizio corretto).
- Se la query chiede il "comando polizia locale" o "polizia municipale" o "vigili urbani", cerca numeri 06.958982XX (non 113/112 che sono emergenze nazionali).
- "Polizia di Stato" = 113 (emergenze)
- "Comando Polizia Locale" = numero specifico del comune (solitamente 06.958982XX)

[resto del prompt...]
```

---

### **Step 8**: Testing Completo

**Test 1: Unit Test StructuredDataExtractor**
```php
// tests/Unit/StructuredDataExtractorTest.php
test('extracts phone and service association', function () {
    $content = "06.95898223\n\nOrari...\n\n**COMANDO POLIZIA LOCALE:**";
    $extractor = new StructuredDataExtractor();
    $result = $extractor->extract($content);
    
    expect($result['associations'])->toHaveCount(1);
    expect($result['associations'][0])->toMatchArray([
        'phone' => '06.95898223',
        'service' => expect::stringContaining('Polizia Locale'),
    ]);
});
```

**Test 2: Integration Test Re-ingestion**
```bash
php backend/reingest_doc_4350_structured.php
php artisan queue:work --once

# Verify
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$chunk = \App\Models\DocumentChunk::where('document_id', 4350)
    ->where('chunk_index', 2)->first();
print_r(\$chunk->structured_data);
"
```

**Test 3: RAG Tester**
1. Query: "telefono comando polizia locale"
2. Expected: "06.95898223" in response
3. Verify: Citations show "📞 Contatti strutturati:" section

**Test 4: Widget**
1. Query: "telefono comando polizia locale"
2. Expected: Same as RAG Tester
3. Verify: Network tab shows correct citations

---

## 🚀 Execution Order

1. ✅ **Step 1**: Analysis (COMPLETED)
2. 🔧 **Step 2**: Implement StructuredDataExtractor (30 min)
3. 🔧 **Step 3**: Add structured_data field to DocumentChunk (10 min)
4. 🔧 **Step 4**: Integrate extraction in ingestion job (15 min)
5. 🔧 **Step 5**: Modify ContextBuilder to use structured data (20 min)
6. 🧪 **Step 6**: Re-ingest doc 4350 (10 min)
7. 🧪 **Step 7**: (Optional) Enhance system prompt (10 min)
8. ✅ **Step 8**: Testing (30 min)

**Total**: ~2-3 hours

---

## 📊 Success Criteria

### Must Have (P0)
- ✅ RAG Tester query "telefono comando polizia locale" returns "06.95898223"
- ✅ Widget query "telefono comando polizia locale" returns "06.95898223"
- ✅ No regression on other queries (e.g., "telefono carabinieri" still works)

### Should Have (P1)
- ✅ Structured data extraction works for 80%+ of contact patterns
- ✅ Context includes "📞 Contatti strutturati:" section
- ✅ Unit tests pass

### Nice to Have (P2)
- ⚪ Extraction works for emails, hours, addresses
- ⚪ Confidence scoring for associations
- ⚪ Fallback to unstructured extraction if no associations found

---

## 🔄 Rollback Plan

If issues arise:
1. Revert ContextBuilder changes
2. Keep structured_data field (for future use)
3. Restore original chunks from backup (if created)

---

## 📝 Next Steps After Completion

1. Monitor production queries for accuracy
2. Expand structured extraction to other document types
3. Consider ML-based entity extraction for complex patterns
4. Create admin UI to view/edit structured data

---

## 🎯 Alternative Quick Fix (If Step 2-5 Too Complex)

**Plan B**: System Prompt Override (5 min)

Update tenant 5 system prompt to be VERY specific:
```
Sei un assistente del Comune di San Cesareo.

REGOLA CRITICA PER "COMANDO POLIZIA LOCALE":
- Il numero del Comando Polizia Locale è 06.95898223
- NON confondere con Polizia di Stato (113) o Carabinieri (06.9587004)
- Se la query chiede "telefono comando polizia locale", rispondi SEMPRE: "06.95898223"

[resto del prompt...]
```

**Pros**: Immediate fix  
**Cons**: Hardcoded, not scalable, hack-ish

Use only as **temporary fix** while implementing proper structured extraction.

---

**Ready to proceed with Step 2?**

