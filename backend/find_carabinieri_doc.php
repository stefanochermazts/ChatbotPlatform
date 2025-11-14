<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo '🔍 Searching for Carabinieri phone number (06.9587004)...'.PHP_EOL.PHP_EOL;

// Find chunks with Carabinieri phone
$chunks = \App\Models\DocumentChunk::where('tenant_id', 5)
    ->whereRaw('content LIKE ?', ['%06.9587004%'])
    ->orWhereRaw('content LIKE ?', ['%06 9587004%'])
    ->get();

echo 'Found '.$chunks->count().' chunks with Carabinieri phone'.PHP_EOL.PHP_EOL;

foreach ($chunks as $chunk) {
    $doc = \App\Models\Document::find($chunk->document_id);

    echo '📄 Document ID: '.$chunk->document_id.PHP_EOL;
    echo '   Title: '.($doc->title ?? 'N/A').PHP_EOL;
    echo '   Chunk Index: '.$chunk->chunk_index.PHP_EOL;
    echo '   Content (first 300 chars):'.PHP_EOL;
    echo '   '.str_repeat('-', 78).PHP_EOL;
    echo '   '.mb_substr($chunk->content, 0, 300).'...'.PHP_EOL;
    echo '   '.str_repeat('-', 78).PHP_EOL;
    echo PHP_EOL;
}

// Also check if "Carabinieri" and "Polizia Locale" appear in same doc
echo PHP_EOL;
echo "🔍 Searching for 'Carabinieri' mentions...".PHP_EOL;
$carabinieriChunks = \App\Models\DocumentChunk::where('tenant_id', 5)
    ->whereRaw('LOWER(content) LIKE ?', ['%carabinieri%'])
    ->get(['document_id', 'chunk_index']);

echo 'Found in '.$carabinieriChunks->unique('document_id')->count().' documents'.PHP_EOL;
foreach ($carabinieriChunks->unique('document_id') as $chunk) {
    $doc = \App\Models\Document::find($chunk->document_id);
    echo '   Doc:'.$chunk->document_id.' - '.($doc->title ?? 'N/A').PHP_EOL;
}
