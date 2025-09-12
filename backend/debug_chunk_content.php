<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔍 DEBUG CHUNK CONTENT - DOC 893\n";
echo "=================================\n\n";

$docId = 893;
$tenantId = 1;

// Ottieni tutti i chunk del documento 893
$chunks = DB::select("
    SELECT chunk_index, content
    FROM document_chunks
    WHERE document_id = ? AND tenant_id = ?
    ORDER BY chunk_index
", [$docId, $tenantId]);

echo "📄 Documento 893 - Totale chunk: " . count($chunks) . "\n\n";

foreach ($chunks as $chunk) {
    echo "--- CHUNK {$chunk->chunk_index} ---\n";
    
    // Cerca telefoni nel chunk
    if (preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $chunk->content, $phoneMatches)) {
        echo "📞 TELEFONI TROVATI: " . implode(', ', $phoneMatches[0]) . "\n";
    }
    
    // Mostra contenuto
    echo substr($chunk->content, 0, 300) . "\n";
    if (strlen($chunk->content) > 300) {
        echo "... (truncated)\n";
    }
    echo "\n";
}

echo "✅ Debug completato!\n";
