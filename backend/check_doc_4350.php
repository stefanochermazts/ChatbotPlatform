<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DocumentChunk;

$chunks = DocumentChunk::where('document_id', 4350)->orderBy('chunk_index')->get(['chunk_index', 'content']);

echo "=== DOCUMENT 4350 - CHUNKS ANALYSIS ===" . PHP_EOL . PHP_EOL;
echo "Total chunks: " . $chunks->count() . PHP_EOL . PHP_EOL;

foreach ($chunks as $c) {
    $text = $c->content;
    $hasPolizia = stripos($text, 'polizia') !== false;
    $hasPhone = stripos($text, '06.95898223') !== false;
    
    echo "=== CHUNK {$c->chunk_index} ===" . PHP_EOL;
    echo "Length: " . strlen($text) . " chars" . PHP_EOL;
    echo "Contains 'polizia': " . ($hasPolizia ? 'YES' : 'NO') . PHP_EOL;
    echo "Contains '06.95898223': " . ($hasPhone ? 'YES' : 'NO') . PHP_EOL;
    echo PHP_EOL;
    
    if ($hasPolizia) {
        echo "--- CONTENT PREVIEW (first 2000 chars) ---" . PHP_EOL;
        echo substr($text, 0, 2000) . PHP_EOL;
        echo str_repeat('=', 80) . PHP_EOL . PHP_EOL;
    }
}

// Search for the specific phone in all chunks
echo "=== SEARCH FOR '06.95898223' ===" . PHP_EOL;
$found = false;
foreach ($chunks as $c) {
    if (stripos($c->content, '06.95898223') !== false) {
        $found = true;
        echo "Found in Chunk {$c->chunk_index}" . PHP_EOL;
        
        // Extract context around the phone
        $pos = stripos($c->content, '06.95898223');
        $start = max(0, $pos - 300);
        $length = 600;
        $context = substr($c->content, $start, $length);
        echo "Context:" . PHP_EOL;
        echo $context . PHP_EOL;
    }
}

if (!$found) {
    echo "❌ Phone '06.95898223' NOT FOUND in any chunk!" . PHP_EOL;
    echo PHP_EOL;
    echo "Searching for similar phones..." . PHP_EOL;
    foreach ($chunks as $c) {
        if (preg_match_all('/06\.95898\d{3}/', $c->content, $matches)) {
            echo "Chunk {$c->chunk_index}: Found phones: " . implode(', ', array_unique($matches[0])) . PHP_EOL;
        }
    }
}

