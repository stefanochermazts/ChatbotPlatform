<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DocumentChunk;

echo '=== INSPECT CHUNK CONTENT ==='.PHP_EOL.PHP_EOL;

$documentId = 4350;
$chunkIndex = 1;

echo "Document ID: {$documentId}".PHP_EOL;
echo "Chunk Index: {$chunkIndex}".PHP_EOL;
echo PHP_EOL;

try {
    $chunk = DocumentChunk::where('document_id', $documentId)
        ->where('chunk_index', $chunkIndex)
        ->first();

    if (! $chunk) {
        echo '❌ Chunk not found!'.PHP_EOL;
        exit(1);
    }

    $content = $chunk->content;
    $contentLower = mb_strtolower($content);

    echo '📄 FULL CHUNK CONTENT:'.PHP_EOL;
    echo str_repeat('=', 80).PHP_EOL;
    echo $content.PHP_EOL;
    echo str_repeat('=', 80).PHP_EOL;
    echo PHP_EOL;

    // Keyword analysis
    echo '🔍 KEYWORD ANALYSIS:'.PHP_EOL;
    echo PHP_EOL;

    $keywords = [
        'telefono' => 0,
        'tel:' => 0,
        'tel.' => 0,
        'phone' => 0,
        'comando' => 0,
        'polizia' => 0,
        'locale' => 0,
        '06.95898223' => 0,
    ];

    foreach ($keywords as $keyword => $count) {
        $keywords[$keyword] = substr_count($contentLower, mb_strtolower($keyword));
    }

    foreach ($keywords as $keyword => $count) {
        $icon = $count > 0 ? '✅' : '❌';
        echo "  {$icon} '{$keyword}': {$count} occurrences".PHP_EOL;
    }

    echo PHP_EOL;

    // Diagnosis
    echo '🎯 DIAGNOSIS:'.PHP_EOL;
    echo PHP_EOL;

    if ($keywords['telefono'] === 0 && ($keywords['tel:'] > 0 || $keywords['tel.'] > 0)) {
        echo '  🔴 CONFIRMED ROOT CAUSE!'.PHP_EOL;
        echo "  - Chunk uses 'tel:' or 'tel.' NOT 'telefono'".PHP_EOL;
        echo "  - FTS query for 'telefono' will FAIL (AND logic)".PHP_EOL;
        echo PHP_EOL;
        echo '💡 SOLUTIONS:'.PHP_EOL;
        echo "  1. Synonym expansion: 'telefono' → ['tel', 'telefono', 'phone']".PHP_EOL;
        echo "  2. Query rewriting: detect 'telefono' intent, expand keywords".PHP_EOL;
        echo '  3. Structured field extraction during ingestion (store phone numbers separately)'.PHP_EOL;
    } elseif ($keywords['telefono'] > 0) {
        echo "  ⚠️  UNEXPECTED: Chunk DOES contain 'telefono'".PHP_EOL;
        echo '  - Need to investigate WHY FTS fails'.PHP_EOL;
        echo '  - Check language configuration or tsquery parsing'.PHP_EOL;
    } else {
        echo "  ⚠️  UNKNOWN: Chunk doesn't contain 'telefono', 'tel:', or 'tel.'".PHP_EOL;
        echo '  - Phone number might be formatted differently'.PHP_EOL;
    }

    exit(0);

} catch (\Throwable $e) {
    echo '❌ EXCEPTION: '.$e->getMessage().PHP_EOL;
    exit(1);
}
