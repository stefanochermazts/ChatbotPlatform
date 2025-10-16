<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Jobs\IngestUploadedDocumentJob;

echo "=== RE-INGESTION DOCUMENTO 4285 ===" . PHP_EOL . PHP_EOL;

$doc = Document::find(4285);
if (!$doc) {
    echo "Documento 4285 non trovato!" . PHP_EOL;
    exit(1);
}

echo "Documento: {$doc->title}" . PHP_EOL;
echo "Source: {$doc->source}" . PHP_EOL;
echo "Source URL: {$doc->source_url}" . PHP_EOL;
echo PHP_EOL;

// Delete existing chunks
echo "Deleting existing chunks..." . PHP_EOL;
$deletedCount = DocumentChunk::where('document_id', 4285)->delete();
echo "Deleted {$deletedCount} chunks" . PHP_EOL . PHP_EOL;

// Update ingestion status
$doc->ingestion_status = 'pending';
$doc->save();

echo "Dispatching ingestion job..." . PHP_EOL;
IngestUploadedDocumentJob::dispatch($doc->id);

echo "✅ Re-ingestion job dispatched!" . PHP_EOL;
echo PHP_EOL;
echo "Run queue worker to process:" . PHP_EOL;
echo "  php artisan queue:work --queue=ingestion" . PHP_EOL;
echo PHP_EOL;
echo "After processing, verify chunks with:" . PHP_EOL;
echo "  php artisan tinker --execute=\"dump(App\\Models\\DocumentChunk::where('document_id', 4285)->count());\"" . PHP_EOL;

