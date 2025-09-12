<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🧹 PULIZIA EMBEDDINGS ORFANI IN MILVUS\n";
echo "======================================\n\n";

$tenantId = 1;

echo "🔍 Analisi documenti orfani...\n";

// Trova tutti i document_id in Milvus per questo tenant
$milvusService = app(\App\Services\RAG\MilvusClient::class);

try {
    // Query Milvus per ottenere tutti i document_id del tenant
    $milvusResults = $milvusService->searchTopK($tenantId, [], 1000); // Dummy search per ottenere IDs
    
    echo "⚠️ Impossibile estrarre document_id da Milvus con questa query.\n";
    echo "📋 MANUALE: Verifica documenti mancanti in PostgreSQL\n\n";
    
    // Alternativa: verifica range di documenti che dovrebbero esistere
    $docIds = range(880, 950); // Range probabile basato sui risultati
    
    $orphanedDocs = [];
    $existingDocs = [];
    
    foreach ($docIds as $docId) {
        $exists = DB::selectOne('SELECT id FROM documents WHERE id = ? AND tenant_id = ?', [$docId, $tenantId]);
        if (!$exists) {
            $orphanedDocs[] = $docId;
        } else {
            $existingDocs[] = $docId;
        }
    }
    
    echo "📊 RISULTATI ANALISI:\n";
    echo "✅ Documenti esistenti: " . count($existingDocs) . "\n";
    echo "❌ Documenti orfani: " . count($orphanedDocs) . "\n\n";
    
    if (!empty($orphanedDocs)) {
        echo "🗑️ Documenti orfani trovati:\n";
        foreach ($orphanedDocs as $docId) {
            echo "  - Doc {$docId}\n";
        }
        echo "\n";
    }
    
    if (!empty($existingDocs)) {
        echo "📄 Documenti esistenti che potrebbero contenere il telefono:\n";
        foreach ($existingDocs as $docId) {
            // Cerca telefono nei chunk
            $hasPhone = DB::selectOne("
                SELECT COUNT(*) as count 
                FROM document_chunks 
                WHERE document_id = ? AND tenant_id = ?
                  AND content ~ '0[0-9]{1,3}[\\s\\.-]*[0-9]{6,8}'
            ", [$docId, $tenantId]);
            
            if ($hasPhone && $hasPhone->count > 0) {
                $doc = DB::selectOne('SELECT title FROM documents WHERE id = ?', [$docId]);
                echo "  📞 Doc {$docId}: " . substr($doc->title ?? 'N/A', 0, 60) . " (contiene telefoni)\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "❌ Errore durante l'analisi: " . $e->getMessage() . "\n";
}

echo "\n💡 AZIONI CONSIGLIATE:\n";
echo "1. Ri-indicizza i documenti mancanti\n";
echo "2. Pulisci Milvus dai documenti orfani\n";
echo "3. Verifica che il documento con il telefono esista ancora\n";

echo "\n✅ Analisi completata!\n";
