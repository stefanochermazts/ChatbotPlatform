<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Models\DocumentChunk;

echo "=== COMPARE FINAL DOCUMENTS ===" . PHP_EOL . PHP_EOL;

$docIds = [4315, 4298, 4304, 4350];

foreach ($docIds as $docId) {
    $doc = Document::find($docId);
    
    if (!$doc) {
        echo "Doc {$docId}: ❌ NOT FOUND" . PHP_EOL . PHP_EOL;
        continue;
    }
    
    echo "Doc {$docId}: {$doc->title}" . PHP_EOL;
    echo "  Source: {$doc->source}" . PHP_EOL;
    echo "  URL: {$doc->source_url}" . PHP_EOL;
    echo "  KB ID: {$doc->knowledge_base_id}" . PHP_EOL;
    
    // Get chunks count
    $chunksCount = DocumentChunk::where('document_id', $docId)->count();
    echo "  Chunks: {$chunksCount}" . PHP_EOL;
    
    // Get first chunk preview with "tel" or "telefono" or "polizia"
    $relevantChunk = DocumentChunk::where('document_id', $docId)
        ->where(function($q) {
            $q->whereRaw("LOWER(content) LIKE ?", ['%tel%'])
              ->orWhereRaw("LOWER(content) LIKE ?", ['%polizia%']);
        })
        ->orderBy('chunk_index')
        ->first();
    
    if ($relevantChunk) {
        $preview = substr($relevantChunk->content, 0, 300);
        echo "  Relevant Chunk #{$relevantChunk->chunk_index}: " . str_replace(["\n", "\r"], ' ', $preview) . "..." . PHP_EOL;
        
        // Check for phone
        if (preg_match('/06\.95898223/', $relevantChunk->content)) {
            echo "  ✅ HAS CORRECT PHONE: 06.95898223" . PHP_EOL;
        }
    }
    
    echo PHP_EOL;
}

echo str_repeat('=', 80) . PHP_EOL;
echo PHP_EOL;

echo "🎯 ANALYSIS:" . PHP_EOL;
echo "  - Docs 4315, 4298, 4304: Selected by MMR (final citations)" . PHP_EOL;
echo "  - Doc 4350: In fusion top-10 (#4, #6) but excluded by MMR" . PHP_EOL;
echo PHP_EOL;
echo "💡 SOLUTION:" . PHP_EOL;
echo "  Increase mmr_take to include doc 4350 in final citations" . PHP_EOL;
echo "  OR boost BM25 scores to rank doc 4350 higher in fusion" . PHP_EOL;

