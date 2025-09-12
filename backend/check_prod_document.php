<?php

/**
 * VERIFICA DOCUMENTO IN PRODUZIONE
 * 
 * Controlla il contenuto del documento 779 per vedere se contiene il telefono
 */

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔍 VERIFICA DOCUMENTO 779 IN PRODUZIONE\n";
echo "======================================\n\n";

$docId = 779;
$tenantId = 1;

// Verifica documento
$doc = \App\Models\Document::find($docId);
if (!$doc) {
    echo "❌ Documento {$docId} non trovato!\n";
    exit(1);
}

echo "📄 Documento trovato:\n";
echo "ID: {$doc->id}\n";
echo "Titolo: {$doc->title}\n";
echo "KB: {$doc->knowledge_base_id}\n";
echo "URL: {$doc->source_url}\n";
echo "Ultima modifica: {$doc->updated_at}\n";
echo "Hash contenuto: {$doc->content_hash}\n\n";

// Cerca chunk con 'polizia'
$chunks = DB::select("
    SELECT chunk_index, content
    FROM document_chunks
    WHERE document_id = ? AND tenant_id = ?
      AND LOWER(content) LIKE '%polizia%'
    ORDER BY chunk_index
", [$docId, $tenantId]);

echo "📋 Chunk con 'polizia' (". count($chunks) ."):\n";
foreach ($chunks as $i => $chunk) {
    echo "\n--- Chunk {$chunk->chunk_index} ---\n";
    echo substr($chunk->content, 0, 500) . "\n";
    
    // Cerca telefoni
    if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $chunk->content, $phoneMatches)) {
        echo "📞 TELEFONI TROVATI: " . implode(', ', $phoneMatches[0]) . "\n";
    } else {
        echo "📞 Nessun telefono in questo chunk\n";
    }
}

// Cerca tutti i chunk per telefoni generici
echo "\n🔍 RICERCA TELEFONI IN TUTTO IL DOCUMENTO:\n";
$allChunks = DB::select("
    SELECT chunk_index, content
    FROM document_chunks
    WHERE document_id = ? AND tenant_id = ?
    ORDER BY chunk_index
", [$docId, $tenantId]);

$foundPhones = [];
foreach ($allChunks as $chunk) {
    if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $chunk->content, $phoneMatches)) {
        foreach ($phoneMatches[0] as $phone) {
            $foundPhones[] = "Chunk {$chunk->chunk_index}: {$phone}";
        }
    }
}

if (empty($foundPhones)) {
    echo "❌ NESSUN TELEFONO TROVATO nell'intero documento!\n";
    echo "\n💡 AZIONE NECESSARIA:\n";
    echo "1. Ri-scrapare il documento dalla fonte\n";
    echo "2. Verificare che l'URL sia accessibile\n";
    echo "3. Controllare se il sito web è cambiato\n";
} else {
    echo "✅ Telefoni trovati:\n";
    foreach ($foundPhones as $phone) {
        echo "  - {$phone}\n";
    }
}

echo "\n✅ Verifica completata!\n";
