<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DocumentChunk;

$chunks = DocumentChunk::where('document_id', 4349)->orderBy('chunk_index')->get(['chunk_index', 'content']);

echo "=== SEARCHING FOR 'POLIZIA' CHUNKS ===".PHP_EOL.PHP_EOL;

foreach ($chunks as $c) {
    $text = $c->content;
    if (stripos($text, 'polizia') !== false) {
        echo "=== CHUNK {$c->chunk_index} ===".PHP_EOL;
        echo 'Length: '.strlen($text).' chars'.PHP_EOL;
        echo PHP_EOL;
        echo $text.PHP_EOL;
        echo str_repeat('=', 80).PHP_EOL.PHP_EOL;
    }
}
