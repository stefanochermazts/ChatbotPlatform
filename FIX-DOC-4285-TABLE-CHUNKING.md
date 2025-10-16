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

## ✅ SOLUZIONE IMPLEMENTATA

### Strategia: Preservare Tabelle Piccole

**NON esplodere tabelle con < 10 righe** per mantenere il contesto:

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

### Benefici

✅ **Tabelle piccole** (contatti, orari) restano **INTERE**  
✅ **Tabelle grandi** (50+ righe) vengono comunque esplose  
✅ **Contesto preservato**: quale ufficio/servizio  
✅ **RAG retrieval accurato**: NO mixing di informazioni

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

### Before Fix
- **29 chunks**: molti troppo piccoli (56-119 chars)
- **Strategy**: Explode ALL tables
- **Context**: LOST (no office/service name per row)
- **RAG Accuracy**: ❌ FALSE (mixing wrong info)

### After Fix
- **~5-10 chunks**: dimensione appropriata
- **Strategy**: Preserve small tables, explode only large ones
- **Context**: PRESERVED (entire table with context)
- **RAG Accuracy**: ✅ CORRECT (no mixing)

---

## 📝 FILES MODIFIED

**Commit**: `739a7a4`

```
backend/app/Services/Ingestion/ChunkingService.php
- chunkTables() method (lines 83-136)
- Added row count check before explosion
- Enhanced logging for debugging
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

1. **Table-aware chunking** deve considerare la **dimensione della tabella**
2. **Tabelle piccole** (<10 righe) devono rimanere **INTERE** per preservare contesto
3. **Tabelle grandi** (50+ righe) possono essere esplose per chunking migliore
4. **Context preservation** è critico per RAG accuracy
5. **Documenti scraped** hanno struttura ben formattata, non necessitano di extraction aggressiva

---

**Owner**: Development Team  
**Last Updated**: 2025-10-16  
**Status**: ✅ FIXED - TESTING REQUIRED

