<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Document;
use App\Models\Tenant;
use App\Jobs\DeleteVectorsJob;
use Illuminate\Support\Facades\Log;

echo "🧪 TEST PROBLEMA SINCRONIZZAZIONE DELETE\n";
echo "==========================================\n\n";

// Usa il documento di test che abbiamo creato (quello con chunks)
// Prima trova tutti i documenti di test
$testDocs = Document::where('tenant_id', 5)
    ->where('title', 'Test Document for Delete Sync')
    ->get();

$doc = null;
foreach ($testDocs as $testDoc) {
    $chunksCount = DB::table('document_chunks')->where('document_id', $testDoc->id)->count();
    if ($chunksCount > 0) {
        $doc = $testDoc;
        break;
    }
}

if (!$doc) {
    echo "❌ Documento di test non trovato! Assicurati di aver eseguito lo script di creazione prima.\n";
    exit(1);
}

echo "📄 Documento di test: {$doc->id} - {$doc->title}\n";

// Controlla quanti chunks ha attualmente
$chunksCount = DB::table('document_chunks')->where('document_id', $doc->id)->count();
echo "📊 Chunks nel documento: {$chunksCount}\n\n";

if ($chunksCount === 0) {
    echo "⚠️ Il documento non ha chunks, non possiamo testare il problema\n";
    exit(0);
}

echo "🔍 SIMULIAMO IL PROBLEMA:\n";
echo "1. Creiamo il job DeleteVectorsJob\n";
echo "2. Cancelliamo i chunks da PostgreSQL (simula la race condition)\n";
echo "3. Eseguiamo il job e vediamo cosa succede\n\n";

// 1. Crea il job (come fa il controller)
echo "1️⃣ Creo DeleteVectorsJob per documento {$doc->id}...\n";
$job = new DeleteVectorsJob([$doc->id]);

// 2. Simula la cancellazione immediata dei chunks (come fa il controller)
echo "2️⃣ Simulo cancellazione chunks da PostgreSQL...\n";
$deletedChunks = DB::table('document_chunks')->where('document_id', $doc->id)->get();
echo "   Chunks trovati prima della cancellazione: " . $deletedChunks->count() . "\n";

// Salva i primary IDs che dovrebbero essere cancellati da Milvus
$expectedPrimaryIds = [];
foreach ($deletedChunks as $chunk) {
    $expectedPrimaryIds[] = ($chunk->document_id * 100000) + $chunk->chunk_index;
}
echo "   Primary IDs che dovrebbero essere cancellati da Milvus: " . implode(', ', $expectedPrimaryIds) . "\n";

// Cancella i chunks (simula il controller)
DB::table('document_chunks')->where('document_id', $doc->id)->delete();
echo "   ✅ Chunks cancellati da PostgreSQL\n\n";

// 3. Ora esegui il job e vedi cosa succede
echo "3️⃣ Eseguo il job DeleteVectorsJob...\n";

try {
    // Crea manualmente l'istanza MilvusClient
    $milvusClient = app()->make(\App\Services\RAG\MilvusClient::class);
    
    // Esegui il job
    $job->handle($milvusClient);
    echo "   ✅ Job eseguito senza errori\n";
    
} catch (Exception $e) {
    echo "   ❌ Errore durante esecuzione job: " . $e->getMessage() . "\n";
}

echo "\n🔍 VERIFICA FINALE:\n";
echo "- Chunks in PostgreSQL per doc {$doc->id}: " . DB::table('document_chunks')->where('document_id', $doc->id)->count() . "\n";
echo "- I primary IDs " . implode(', ', $expectedPrimaryIds) . " dovrebbero essere stati cancellati da Milvus\n";
echo "  (ma probabilmente NON lo sono stati, dimostrando il problema!)\n";

echo "\n💡 SOLUZIONE: Modificare DeleteVectorsJob per calcolare primaryIds PRIMA di cercare chunks in PostgreSQL\n";
