<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DocumentChunk;

echo "=== INSPECT DOC 4350 CHUNKS ===" . PHP_EOL . PHP_EOL;

$chunks = DocumentChunk::where('document_id', 4350)
    ->orderBy('chunk_index')
    ->get(['chunk_index', 'content']);

echo "Total chunks: " . $chunks->count() . PHP_EOL . PHP_EOL;

foreach ($chunks as $chunk) {
    echo str_repeat('=', 100) . PHP_EOL;
    echo "CHUNK #{$chunk->chunk_index}" . PHP_EOL;
    echo str_repeat('=', 100) . PHP_EOL;
    echo $chunk->content . PHP_EOL;
    echo PHP_EOL;
}

echo str_repeat('=', 100) . PHP_EOL;
echo "✅ Inspection complete" . PHP_EOL;

