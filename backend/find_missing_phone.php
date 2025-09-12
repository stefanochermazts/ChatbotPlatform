<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "🔍 RICERCA TELEFONO MANCANTE\n";
echo "============================\n\n";

$targetPhone = 'tel:06.95898223';
echo "🎯 Telefono cercato: {$targetPhone}\n\n";

// 1. Cerca in tutti i chunk del tenant 1
echo "1️⃣ RICERCA IN TUTTI I CHUNK\n";
echo "----------------------------\n";

$chunks = DB::select("
    SELECT dc.document_id, dc.chunk_index, dc.content, d.title
    FROM document_chunks dc
    JOIN documents d ON dc.document_id = d.id
    WHERE dc.tenant_id = 1 
      AND dc.content ILIKE '%06.95898223%'
    ORDER BY dc.document_id, dc.chunk_index
");

if (empty($chunks)) {
    echo "❌ TELEFONO NON TROVATO nei chunk!\n\n";
    
    // 2. Cerca nel contenuto completo dei documenti
    echo "2️⃣ RICERCA NEI DOCUMENTI COMPLETI\n";
    echo "----------------------------------\n";
    
    $docs = DB::select("
        SELECT id, title, file_content
        FROM documents
        WHERE tenant_id = 1 
          AND file_content ILIKE '%06.95898223%'
    ");
    
    if (empty($docs)) {
        echo "❌ TELEFONO NON TROVATO nei documenti!\n\n";
        
        // 3. Cerca pattern simili
        echo "3️⃣ RICERCA PATTERN SIMILI\n";
        echo "--------------------------\n";
        
        $similar = DB::select("
            SELECT dc.document_id, dc.chunk_index, dc.content
            FROM document_chunks dc
            WHERE dc.tenant_id = 1 
              AND (dc.content ILIKE '%95898223%' OR dc.content ILIKE '%polizia%telefon%' OR dc.content ILIKE '%comando%tel%')
            LIMIT 10
        ");
        
        foreach ($similar as $chunk) {
            echo "Doc {$chunk->document_id}, Chunk {$chunk->chunk_index}:\n";
            echo substr($chunk->content, 0, 200) . "...\n\n";
        }
        
    } else {
        foreach ($docs as $doc) {
            echo "✅ Doc {$doc->id}: {$doc->title}\n";
            
            // Cerca il pattern nel contenuto
            if (preg_match('/(.{0,100}06\.95898223.{0,100})/i', $doc->file_content, $matches)) {
                echo "Contesto: " . $matches[0] . "\n";
            }
            echo "\n";
        }
    }
    
} else {
    foreach ($chunks as $chunk) {
        echo "✅ TROVATO in Doc {$chunk->document_id}, Chunk {$chunk->chunk_index}\n";
        echo "Titolo: {$chunk->title}\n";
        
        // Mostra il contesto
        if (preg_match('/(.{0,100}06\.95898223.{0,100})/i', $chunk->content, $matches)) {
            echo "Contesto: " . $matches[0] . "\n";
        }
        echo "\n";
    }
}

echo "✅ Ricerca completata!\n";
