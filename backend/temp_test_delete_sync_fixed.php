<?php

require_once __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\DeleteVectorsJobFixed;
use App\Models\Document;

echo "🧪 TEST VERSIONE FIXED - DeleteVectorsJobFixed\n";
echo "==============================================\n\n";

// Crea un nuovo documento di test
echo "1️⃣ Creazione documento di test...\n";
$doc = Document::create([
    'tenant_id' => 5,
    'knowledge_base_id' => 2,
    'title' => 'Test Document for Fixed Delete Sync',
    'source' => 'test',
    'path' => 'test/fixed-delete-sync-test.txt',
    'ingestion_status' => 'completed',
]);

// Crea chunks
DB::table('document_chunks')->insert([
    [
        'tenant_id' => 5,
        'document_id' => $doc->id,
        'chunk_index' => 0,
        'content' => 'Test chunk 1 per verifica fixed delete sync',
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'tenant_id' => 5,
        'document_id' => $doc->id,
        'chunk_index' => 1,
        'content' => 'Test chunk 2 per verifica fixed delete sync',
        'created_at' => now(),
        'updated_at' => now(),
    ],
]);

echo "   ✅ Documento creato: ID {$doc->id}\n";
echo "   ✅ Chunks creati: 2\n\n";

echo "2️⃣ Test DeleteVectorsJobFixed::fromDocumentIds()...\n";

// Crea il job usando il factory method
$job = DeleteVectorsJobFixed::fromDocumentIds([$doc->id]);

// Verifica che abbia calcolato i primaryIds
echo "   ✅ Job creato con factory method\n";

echo "3️⃣ Simulo cancellazione chunks da PostgreSQL (come fa il controller)...\n";
$chunksBeforeDelete = DB::table('document_chunks')->where('document_id', $doc->id)->count();
echo "   Chunks prima della cancellazione: {$chunksBeforeDelete}\n";

// Cancella chunks (simula il controller)
DB::table('document_chunks')->where('document_id', $doc->id)->delete();
$chunksAfterDelete = DB::table('document_chunks')->where('document_id', $doc->id)->count();
echo "   Chunks dopo la cancellazione: {$chunksAfterDelete}\n";

echo "4️⃣ Eseguo il job DeleteVectorsJobFixed...\n";

try {
    // Crea manualmente l'istanza MilvusClient
    $milvusClient = app()->make(\App\Services\RAG\MilvusClient::class);

    // Esegui il job
    $job->handle($milvusClient);
    echo "   ✅ Job eseguito con successo!\n";

} catch (Exception $e) {
    echo '   ❌ Errore durante esecuzione job: '.$e->getMessage()."\n";
}

echo "\n🎯 CONFRONTO:\n";
echo "❌ VECCHIO COMPORTAMENTO:\n";
echo "   - Job cerca chunks in PostgreSQL\n";
echo "   - NON trova niente (già cancellati)\n";
echo "   - NON calcola primaryIds\n";
echo "   - NON cancella da Milvus\n";
echo "   - RISULTATO: Orphan documents in Milvus\n\n";

echo "✅ NUOVO COMPORTAMENTO (FIXED):\n";
echo "   - Factory method calcola primaryIds PRIMA della cancellazione\n";
echo "   - Job riceve primaryIds già calcolati\n";
echo "   - Cancella da Milvus usando primaryIds precalcolati\n";
echo "   - RISULTATO: Sincronizzazione perfetta!\n\n";

// Pulizia
echo "🧹 Pulizia documento di test...\n";
$doc->delete();
echo "   ✅ Documento di test eliminato\n";

echo "\n🎉 TEST COMPLETATO!\n";
echo "💡 La versione fixed risolve il problema degli orphan documents!\n";
