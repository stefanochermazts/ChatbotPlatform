# Diagnosi RAG: Perché "chi sono i consiglieri" non funziona

## Problema Originale

RAG non trova i consiglieri comunali dalla pagina `https://www.comune.sancesareo.rm.it/zf/index.php/organi-politico-amministrativo` nonostante:
- ✅ Pagina parsata correttamente
- ✅ 3 chunk creati (IDs: 192, 193, 194)
- ✅ Contenuto include tutti i consiglieri

## Root Cause: Documenti Zombie in Milvus

### Scoperta

Query test: `"chi sono i consiglieri comunali"`

**Risultati Milvus**:
```json
{
  "hits": [
    {"id": 429200002, "score": 0.456, "doc_id": 4292},  // ❌ ZOMBIE!
    {"id": 2600001, "score": 0.461, "doc_id": 26},      // ✅ Corretto
    {"id": 429200001, "score": 0.464, "doc_id": 4292},  // ❌ ZOMBIE!
    {"id": 2600002, "score": 0.482, "doc_id": 26}       // ✅ Corretto
  ]
}
```

**Doc 4292**:
- ❌ **NON esiste** in PostgreSQL (`Document::find(4292)` returns null)
- ✅ **Esiste** in Milvus (2 chunk con score migliore di Doc 26)
- ❌ Blocca il Doc 26 legittimo occupando le prime posizioni

### Verifica Infrastruttura

✅ **Milvus funziona correttamente**:
- Connessione: OK
- Collection: `kb_chunks_v1` (979 entità totali)
- Filtro tenant: APPLICATO (`expr=f"tenant_id == {tenant_id}"`)

✅ **Doc 26 indicizzato correttamente**:
```bash
$ python milvus_search.py '{"operation": "count_by_document", "tenant_id": 5, "document_id": 26}'
{"success": true, "count": 3, "chunk_indices": [0, 1, 2]}
```

✅ **Embeddings generati**:
- Dimensioni: 3072 (text-embedding-3-large)
- OpenAI embeddings service: funziona

### Il Problema

**Milvus contiene documenti cancellati da PostgreSQL**:

1. Doc 4292 è stato **cancellato** da PostgreSQL (o appartiene a tenant diverso)
2. Doc 4292 **NON è stato cancellato** da Milvus
3. Quando RAG cerca "consiglieri", Doc 4292 zombie ha score migliore di Doc 26
4. Il reranking e RRF fusion danno priorità ai chunk zombie
5. Il RAG restituisce risultati irrilevanti o confusi

## Impact

**Severity**: 🔴 CRITICO

**Conseguenze**:
- ❌ Query non trovano documenti corretti
- ❌ Risultati di scarsa qualità
- ❌ Confidence scores artificialmente bassi
- ❌ User experience degradata

**Scope**:
- Tenant 5 (DEV): 572 documenti in Milvus
- Tenant 1 (PROD): ~400 documenti in Milvus
- Numero zombie documents: **SCONOSCIUTO** (richiede audit)

## Soluzione

### 1. Cleanup Immediato (Tenant 5)

```bash
cd backend

# Trova tutti i doc_id in Milvus per tenant 5
# (richiede script custom)

# Per ogni doc_id in Milvus:
#   - Verifica se esiste in PostgreSQL
#   - Se NON esiste -> cancella da Milvus

php artisan milvus:cleanup-zombies --tenant=5 --dry-run
php artisan milvus:cleanup-zombies --tenant=5  # Esecuzione reale
```

### 2. Fix Permanente: Sincronizzazione Delete

Modificare `Document::delete()` o observer per cancellare anche da Milvus:

```php
// app/Models/Document.php o app/Observers/DocumentObserver.php

public function deleted(Document $document)
{
    $milvus = app(MilvusClient::class);
    
    // Calcola primary IDs dei chunk
    $chunkCount = $document->chunks()->count();
    $primaryIds = [];
    for ($i = 0; $i < $chunkCount; $i++) {
        $primaryIds[] = ($document->id * 100000) + $i;
    }
    
    // Cancella da Milvus
    if (!empty($primaryIds)) {
        $milvus->deleteByPrimaryIds($primaryIds);
        Log::info('Document deleted from Milvus', [
            'document_id' => $document->id,
            'chunk_count' => $chunkCount
        ]);
    }
}
```

### 3. Comando Artisan per Audit

```php
// app/Console/Commands/MilvusAuditCommand.php

php artisan milvus:audit --tenant=5
// Output:
// ✅ 550 documenti sincronizzati
// ❌ 22 documenti zombie in Milvus
// 📊 List di zombie doc_ids: 4292, 4328, ...
```

### 4. Scheduled Job per Sync

```php
// app/Console/Kernel.php

$schedule->command('milvus:audit --tenant=all --fix')
         ->daily()
         ->at('03:00');
```

## Workaround Temporaneo

### Opzione A: Cancella manualmente Doc 4292

```bash
cd backend
python milvus_search.py '{
  "operation": "delete_by_ids",
  "primary_ids": [429200001, 429200002]
}'
```

### Opzione B: Rigenera index Milvus per tenant 5

```bash
# 1. Cancella tutti i doc del tenant 5 da Milvus
python milvus_search.py '{"operation": "delete_by_tenant", "tenant_id": 5}'

# 2. Rilancia embeddings per tutti i documenti
php artisan queue:work --queue=embeddings --timeout=300

# Oppure dispatch manuale:
php artisan tinker
```

```php
$docs = App\Models\Document::where('tenant_id', 5)->get();
foreach ($docs as $doc) {
    foreach ($doc->chunks as $chunk) {
        App\Jobs\GenerateEmbeddingJob::dispatch($chunk->id, $doc->tenant_id);
    }
}
```

## Test di Verifica

### Test 1: Rimozione Zombie

```bash
# Prima: Doc 4292 appare nei risultati
python milvus_search.py '@temp_search_params.json' | grep 4292
# Output: "id": 429200001, "id": 429200002

# Rimuovi Doc 4292
python milvus_search.py '{"operation": "delete_by_ids", "primary_ids": [429200001, 429200002]}'

# Dopo: Doc 4292 NON appare
python milvus_search.py '@temp_search_params.json' | grep 4292
# Output: (nessun risultato)
```

### Test 2: RAG Query

```bash
php artisan tinker --execute="
\$kbSearch = app(App\Services\RAG\KbSearchService::class);
\$result = \$kbSearch->retrieve(5, 'chi sono i consiglieri', false);
echo 'Top result doc_id: ' . \$result['citations'][0]['document_id'];
"
# Expected: 26
```

### Test 3: Audit Completo

```bash
php artisan milvus:audit --tenant=5
# Expected output:
# ✅ 0 zombie documents
# ✅ All Milvus doc_ids exist in PostgreSQL
```

## Prevenzione

### Checklist

1. ✅ Implementare `DocumentObserver::deleted()` per sync Milvus
2. ✅ Creare comando `milvus:audit` per verificare consistency
3. ✅ Scheduled job giornaliero per cleanup
4. ✅ Monitoring: alert se zombie_count > 5%
5. ✅ Documentation: processo di delete documenti

### Monitoring Queries

```sql
-- PostgreSQL: Count documenti per tenant
SELECT tenant_id, COUNT(*) 
FROM documents 
WHERE tenant_id IN (1, 5)
GROUP BY tenant_id;

-- Output:
-- tenant_id | count
-- 1         | 387
-- 5         | 550
```

```bash
# Milvus: Count documenti per tenant
python milvus_search.py '{"operation": "count_by_tenant", "tenant_id": 5}'
# {"success": true, "count": 572}  # ❌ 22 zombie!

# Differenza = zombie documents
```

## Conclusione

**Problema**: Documenti zombie in Milvus inquinano i risultati RAG

**Causa**: Nessuna sincronizzazione delete PostgreSQL → Milvus

**Fix Immediato**: Cancellare manualmente Doc 4292 (e altri zombie)

**Fix Permanente**: 
1. DocumentObserver per sync delete
2. Comando audit per verificare consistency
3. Scheduled job per cleanup automatico

**Priorità**: 🔴 ALTA - blocca RAG functionality

---

**Data diagnosi**: 2025-01-27  
**Tenant affetto**: 5 (DEV), probabilmente anche 1 (PROD)  
**Documenti zombie identificati**: Doc 4292, altri da verificare con audit  
**Status**: In attesa implementazione fix

