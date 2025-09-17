# 🔧 Risoluzione Race Conditions nel Scraping

## Problema: SQLSTATE[23505] Duplicate Key Violation

### ❌ **Errore Tipico**
```
SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "document_chunks_document_id_chunk_index_unique"
DETAIL: Key (document_id, chunk_index)=(2423, 0) already exists.
```

### 🔍 **Causa**
Questo errore si verifica quando **più processi di scraping** tentano di processare lo stesso documento contemporaneamente, causando:

1. **Race Condition nell'Ingestion**: Due job `IngestUploadedDocumentJob` processano lo stesso documento
2. **Operazioni Non-Atomiche**: DELETE + INSERT non sono in transazione unica
3. **Mancanza di Lock**: Nessun meccanismo di locking sui documenti

### ✅ **Soluzioni Implementate**

#### **1. Transazione Atomica nei Chunks**
**File**: `backend/app/Jobs/IngestUploadedDocumentJob.php`

```php
// PRIMA (problematico)
DB::table('document_chunks')->where('document_id', $doc->id)->delete();
foreach (array_chunk($rows, 500) as $batch) {
    DB::table('document_chunks')->insert($batch);
}

// DOPO (sicuro)
DB::transaction(function () use ($doc, $chunks) {
    $doc->refresh();
    $doc->lockForUpdate(); // 🔒 LOCK del documento
    
    DB::table('document_chunks')->where('document_id', $doc->id)->delete();
    
    // ... insert chunks ...
    
    Log::debug('document_chunks.replaced_atomically', [...]);
});
```

#### **2. Protezione Duplicazione Job**
**File**: `backend/app/Jobs/IngestUploadedDocumentJob.php`

```php
// Verifica se già in processing
if ($doc->ingestion_status === 'processing') {
    Log::info('ingestion.already_processing', [
        'document_id' => $this->documentId,
        'status' => $doc->ingestion_status
    ]);
    return; // Skip se già processato
}
```

#### **3. Gestione Errori Duplicazione**
**File**: `backend/app/Services/Scraper/WebScraperService.php`

```php
try {
    $document = $this->createNewDocument($tenant, $result, $markdownContent, $contentHash);
    // ... success logic ...
} catch (\Illuminate\Database\QueryException $e) {
    if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), '23505')) {
        \Log::warning("🔄 Documento probabilmente già creato da processo concorrente", [
            'url' => $result['url'],
            'error' => $e->getMessage()
        ]);
        // Skip invece di fallire
        $this->stats['skipped']++;
    } else {
        throw $e; // Rilancia altri errori
    }
}
```

### 🧹 **Comando di Cleanup**

Per pulire eventuali duplicati già esistenti:

```bash
# Verifica duplicati (dry-run)
php artisan chunks:clean-duplicates --dry-run

# Verifica per tenant specifico
php artisan chunks:clean-duplicates --tenant=9 --dry-run

# Pulizia effettiva
php artisan chunks:clean-duplicates

# Pulizia per tenant specifico
php artisan chunks:clean-duplicates --tenant=9
```

**Output Esempio**:
```
🧹 Cleaning duplicate document chunks...
🎯 Filtering by tenant ID: 9
⚠️  Found 3 sets of duplicate chunks
🔍 Processing document 2423, chunk 0 (3 duplicates)
  ✅ Keeping chunk ID 12345 (created: 2025-09-17 10:30:15)
  🗑️  Deleting chunk ID 12346 (created: 2025-09-17 10:30:16)
  🗑️  Deleting chunk ID 12347 (created: 2025-09-17 10:30:17)
✅ CLEANUP COMPLETE - Cleaned 6 duplicate chunks
```

### 🚀 **Prevenzione Futura**

#### **1. Worker Separation**
```bash
# Scraping worker (separato da ingestion)
php artisan queue:work --queue=scraping --max-jobs=10

# Ingestion worker (maggiore concorrenza)
php artisan queue:work --queue=ingestion --max-jobs=5
```

#### **2. Monitoring**
```bash
# Log dei chunk duplicati
tail -f storage/logs/laravel.log | grep "duplicate key\|already_processing\|replaced_atomically"

# Verifica queue status
php artisan queue:monitor scraping,ingestion
```

#### **3. Database Constraints**
Il constraint `document_chunks_document_id_chunk_index_unique` è **essenziale** e non deve essere rimosso:

```sql
-- ✅ MANTIENI questo constraint
ALTER TABLE document_chunks 
ADD CONSTRAINT document_chunks_document_id_chunk_index_unique 
UNIQUE (document_id, chunk_index);
```

### 📊 **Sistemi di Monitoraggio**

#### **Script di Verifica**
```bash
#!/bin/bash
# check-chunks-health.sh

echo "🔍 Verifica Salute Chunks..."

# Count totale chunks
TOTAL_CHUNKS=$(psql -d chatbot_db -t -c "SELECT COUNT(*) FROM document_chunks;")
echo "📊 Total chunks: $TOTAL_CHUNKS"

# Verifica duplicati
DUPLICATES=$(psql -d chatbot_db -t -c "
SELECT COUNT(*) FROM (
    SELECT document_id, chunk_index, COUNT(*) 
    FROM document_chunks 
    GROUP BY document_id, chunk_index 
    HAVING COUNT(*) > 1
) as duplicates;")

if [ "$DUPLICATES" -gt 0 ]; then
    echo "⚠️  DUPLICATI TROVATI: $DUPLICATES"
    echo "🧹 Esegui: php artisan chunks:clean-duplicates"
else
    echo "✅ Nessun duplicato trovato"
fi

# Jobs in coda
INGESTION_JOBS=$(php artisan queue:size ingestion)
echo "📋 Jobs ingestion in coda: $INGESTION_JOBS"
```

### 🎯 **Best Practices**

1. **🔒 Sempre Lock**: Usa `lockForUpdate()` per operazioni critiche
2. **⚛️ Transazioni Atomiche**: DELETE + INSERT in una transazione
3. **🛡️ Error Handling**: Gestisci errori di duplicazione come skip, non fail
4. **📊 Monitoring**: Log dettagliati per troubleshooting
5. **🧹 Cleanup Regolare**: Esegui cleanup periodico se necessario
6. **🚀 Worker Separation**: Separa code per ridurre contention

### 🔧 **Emergency Response**

Se l'errore si verifica in produzione:

```bash
# 1. Stop workers temporaneamente
sudo supervisorctl stop chatbot_ingestion:*

# 2. Pulisci duplicati
php artisan chunks:clean-duplicates

# 3. Restart workers
sudo supervisorctl start chatbot_ingestion:*

# 4. Monitor logs
tail -f storage/logs/laravel.log | grep -E "(duplicate|race|concurrent)"
```

Queste implementazioni rendono il sistema **robusto contro race conditions** mantenendo **performance elevate** e **data integrity**.
