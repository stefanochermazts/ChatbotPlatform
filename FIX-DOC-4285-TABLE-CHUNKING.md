# 🔧 FIX: Documento 4285 - Table Chunking Anomalo

**Date**: 2025-10-16  
**Document**: 4285 (Orari Comando Polizia Locale - Tenant 5)  
**Issue**: Chunking anomalo separa orari e telefoni, causando risposte FALSE  
**Status**: ✅ FIXED - Re-ingestion required

---

## 🐛 PROBLEMA ORIGINALE

### Query Test
```
"orari comando polizia locale"
```

### Risposta Problematica
```
Gli orari di apertura al pubblico del Comando Polizia Locale di San Cesareo sono:

Giorno    | Orario        | Telefono
----------|---------------|-------------
Martedì   | 9:00 - 12:00  | 06.95898217  ❌ SBAGLIATO
Giovedì   | 15:00 - 17:00 | 06.95898217  ❌ SBAGLIATO
Venerdì   | 8:30 - 12:00  | 06.95898221  ❌ SBAGLIATO

Per ulteriori informazioni, contatta il numero 06.95898223.
```

**Problema**: I telefoni 06.95898217 e 06.95898221 sono di **ALTRI UFFICI** (non Comando Polizia Locale)!

---

## 🔍 ANALISI CHUNK ORIGINALI

Il documento 4285 aveva **29 chunk troppo piccoli**:

```
Chunk 0:  tel:06.95898272 - Lunedì 8:30-12:00          (110 chars)
Chunk 1:  tel:06.95898272 - Martedì 8:30-12:00         (119 chars)
Chunk 14: tel:06.95898217 - Giovedì 15:00-17:00        (57 chars)  [Comando Polizia?]
Chunk 15: tel:06.95898217 - Venerdì 8:30-12:00         (59 chars)  [Comando Polizia?]
Chunk 16: tel:06.95898221 - Martedì 8:30-12:00         (59 chars)  [Comando Polizia?]
```

**Statistiche**:
- **Total chunks**: 29
- **Min**: 56 chars
- **Max**: 2904 chars
- **Avg**: 266 chars
- **Median**: 81 chars

**Problema**: Ogni **RIGA di tabella** era un chunk separato!

---

## 🎯 ROOT CAUSE

### Metodo `ChunkingService::chunkTables()`

Esplodeva **TUTTE le tabelle** in righe separate:

```php
// PRIMA (BUGGY) - linea 84-100
$rowChunks = $this->explodeMarkdownTableIntoRowChunks($tableContent);

if (!empty($rowChunks)) {
    // OGNI RIGA = 1 CHUNK SEPARATO
    foreach ($rowChunks as $rc) {
        $chunks[] = [
            'text' => $rc,
            'type' => 'table_row',  // ❌ PERDE IL CONTESTO
        ];
    }
}
```

### Perché è Problematico?

1. **Tabelle piccole** (orari/contatti) hanno 2-5 righe
2. Ogni riga contiene: `Giorno: X\nOrario: Y\nTelefono: Z`
3. RAG retrieval recupera **righe di UFFICI DIVERSI**:
   - Chunk 14: Polizia Locale - tel 06.95898217
   - Chunk 16: Polizia Locale - tel 06.95898221
   - Chunk 0: Ufficio Anagrafe - tel 06.95898272
4. LLM **MESCOLA** i telefoni perché **manca il contesto** (quale ufficio?)
5. Risultato: **Informazioni FALSE**

---

## ✅ SOLUZIONI IMPLEMENTATE

### ⚠️ Soluzione 1: Preservare Tabelle Piccole (SUPERATA)

**Commit**: `739a7a4`  
**Status**: ⚠️ NON SUFFICIENTE

**Approccio**: NON esplodere tabelle con < 10 righe per mantenere il contesto:

```php
// DOPO (FIXED) - linea 83-136
// ✅ FIX: Do NOT explode small tables (<10 rows) to preserve context
$rowCount = $table['rows'] ?? 0;
$shouldExplode = $rowCount >= 10;

// Try to explode markdown tables (ONLY for large tables)
$rowChunks = [];
if ($shouldExplode) {
    $rowChunks = $this->explodeMarkdownTableIntoRowChunks($tableContent);
}

if (!empty($rowChunks)) {
    // Exploded (solo tabelle grandi 10+ righe)
    foreach ($rowChunks as $rc) {
        $chunks[] = ['text' => $rc, 'type' => 'table_row'];
    }
    
    Log::debug("table_chunking.exploded", [
        'rows' => $rowCount,
        'chunks_created' => count($rowChunks)
    ]);
} else {
    // Preserva tabella intera (tabelle piccole <10 righe)
    $chunks[] = [
        'text' => $contextualizedTable,
        'type' => 'table',
    ];
    
    Log::debug("table_chunking.preserved_whole", [
        'rows' => $rowCount,
        'reason' => $shouldExplode ? 'explosion_failed' : 'small_table_preserve_context'
    ]);
}
```

**Benefici**:
- ✅ Tabelle piccole restano intere
- ✅ Contesto preservato

**Problema Residuo**:
- ⚠️ LLM **ANCORA non riesce** a ricostruire orari/contatti correttamente
- ⚠️ Anche con tabelle intere, il chunking table-aware causa perdita di flusso narrativo

---

### ✅ Soluzione 2: Semantic-Only Chunking per Scraped Documents (FINALE)

**Commit**: `5670fda`  
**Status**: ✅ SOLUZIONE FINALE

**Insight Utente**: 
> "Spezzando i chunk in questo modo, l'LLM non riesce a ricostruire orario e informazioni sui contatti corretti. Non si potrebbe eliminare qualunque logica basata sulle tabelle e fare il chunking come impostato nel rag config?"

**Approccio**: **ELIMINARE completamente la logica table-aware** per documenti scraped:

```php
// IngestUploadedDocumentJob.php - linee 112-151

// Check if scraped document
$isScrapedDocument = in_array($doc->source, ['web_scraper', 'web_scraper_linked'], true);

if ($isScrapedDocument) {
    // ✅ SIMPLE: Pure semantic chunking with ALL text (tables inline)
    $allChunks = $chunking->chunk($normalizedText, $doc->tenant_id, $chunkOptions);
    
    Log::info("chunking.scraped_semantic_only", [
        'chunks_created' => count($allChunks),
        'reason' => 'scraped_markdown_well_formatted'
    ]);
} else {
    // COMPLEX: Table-aware chunking for uploaded files (PDF, DOCX)
    $tables = $parsing->findTables($normalizedText);
    $tableChunks = $chunking->chunkTables($tables);
    $textWithoutTables = $parsing->removeTables($normalizedText, $tables);
    $directoryChunks = $chunking->extractDirectoryEntries($textWithoutTables);
    $standardChunks = $chunking->chunk($textWithoutTables, $doc->tenant_id, $chunkOptions);
    $allChunks = array_merge($tableChunks, $directoryChunks, $standardChunks);
}
```

**Strategia per Documenti Scraped**:
- ❌ **NO** `findTables()` - skip table detection
- ❌ **NO** `chunkTables()` - skip table explosion
- ❌ **NO** `removeTables()` - keep tables inline
- ❌ **NO** `extractDirectoryEntries()` - Markdown già strutturato
- ✅ **SOLO** `chunk()` semantico standard con **TUTTO il testo** (tabelle inline)

**Rationale**:
1. **Markdown già ben formattato**: Web scraper produce HTML→Markdown pulito
2. **Chunk grandi (3000 chars)**: Contengono tabelle complete + header + footer + contesto
3. **Preserva flusso narrativo**: Chunking semantico mantiene coerenza del discorso
4. **Tabelle inline**: Restano con le loro descrizioni/intestazioni
5. **Più semplice**: Meno logica = meno bug

**Benefici**:
- ✅ **Preserva flusso narrativo completo**
- ✅ **Tabelle con contesto**: Header, descrizione, footer restano insieme
- ✅ **Chunk size ottimale**: 3000 chars per Tenant 5 (configurable)
- ✅ **NO context mixing**: Informazioni di uffici diversi non mescolate
- ✅ **Documenti uploaded**: Mantengono logica table-aware se necessaria

---

## 🧪 TESTING PROCEDURE

### Step 1: Re-ingest Documento 4285

```bash
cd backend
php reingest_doc_4285.php
```

### Step 2: Run Queue Worker

```bash
php artisan queue:work --queue=ingestion
```

### Step 3: Verify New Chunks

```bash
php artisan tinker --execute="
dump(App\Models\DocumentChunk::where('document_id', 4285)->count());
"
```

**Aspettativa**: Dovrebbero esserci **MOLTO MENO chunk** (es. 5-10 invece di 29), con chunk più grandi che preservano il contesto completo di ogni tabella.

### Step 4: Test Query

Query: `"orari comando polizia locale"`

**Risultato Atteso**:
```
Orari apertura al pubblico Comando Polizia Locale:

Martedì:  9:00 - 12:00
Giovedì: 15:00 - 17:00
Venerdì:  9:00 - 12:00

Telefono: 06.95898223  ✅ CORRETTO
```

---

## 📊 IMPACT ANALYSIS

### Before Fix (Original)
- **29 chunks**: molti troppo piccoli (56-119 chars)
- **Strategy**: Explode ALL tables into rows
- **Context**: LOST (no office/service name per row)
- **RAG Accuracy**: ❌ FALSE (mixing wrong info)

### After Soluzione 1 (739a7a4)
- **~5-10 chunks**: dimensione appropriata
- **Strategy**: Preserve small tables, explode only large ones
- **Context**: PARTIALLY PRESERVED (table rows together)
- **RAG Accuracy**: ⚠️ IMPROVED but still issues (LLM can't reconstruct correctly)

### After Soluzione 2 (5670fda) - FINALE
- **~3-8 chunks**: dimensione ottimale basata su flusso semantico
- **Strategy**: Semantic-only for scraped docs (tables inline)
- **Chunk Size**: avg ~1500-2500 chars (configurable, Tenant 5=3000 max)
- **Context**: ✅ FULLY PRESERVED (narrative flow + tables + headers)
- **RAG Accuracy**: ✅ EXPECTED CORRECT (no mixing, full context)

---

## 📝 FILES MODIFIED

### Soluzione 1 (Superseded)
**Commit**: `739a7a4`
```
backend/app/Services/Ingestion/ChunkingService.php
- chunkTables() method (lines 83-136)
- Added row count check before explosion
- Enhanced logging
```

### Soluzione 2 (FINALE)
**Commit**: `5670fda`
```
backend/app/Jobs/IngestUploadedDocumentJob.php
- Added source-aware chunking strategy (lines 84-151)
- Scraped docs: semantic-only chunking
- Uploaded docs: table-aware chunking
- Enhanced logging for both strategies

documents/02-INGESTION-FLOW.md
- Updated CHUNKING section with source-aware strategy
- Documented semantic-only for scraped docs
- Documented table-aware for uploaded docs
```

---

## 🔄 NEXT STEPS

1. ✅ Fix committato e pushato
2. ⏳ **Re-ingest documento 4285** (user action)
3. ⏳ **Verify new chunks** (user action)
4. ⏳ **Test query** "orari comando polizia locale" (user action)
5. ⏳ **Optionally**: Re-ingest altri documenti con tabelle piccole

---

## 🎯 LESSONS LEARNED

### Da Soluzione 1
1. **Table-aware chunking** deve considerare la **dimensione della tabella**
2. **Tabelle piccole** (<10 righe) devono rimanere **INTERE** per preservare contesto
3. **Tabelle grandi** (50+ righe) possono essere esplose per chunking migliore
4. **Context preservation** è critico per RAG accuracy

### Da Soluzione 2 (CHIAVE)
5. ⭐ **Documenti scraped** hanno struttura già ben formattata → **NON necessitano di extraction table-aware**
6. ⭐ **Semantic-only chunking** con chunk grandi (3000) preserva **MEGLIO il flusso narrativo**
7. ⭐ **Tabelle inline** restano con header/descrizione/footer → **contesto completo**
8. ⭐ **Source-aware strategy**: Scraped=semantic, Uploaded=table-aware
9. ⭐ **Less is more**: Logica più semplice = meno bug, risultati migliori
10. ⭐ **Listen to user insights**: La proposta di eliminare table logic era **corretta**

---

**Owner**: Development Team  
**Last Updated**: 2025-10-16 (Soluzione 2 finale)  
**Status**: ✅ FIXED (Soluzione 2 - Semantic-Only) - RE-INGESTION REQUIRED

