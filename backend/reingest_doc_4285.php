<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;
use App\Models\DocumentChunk;

// Find latest Comando Polizia Locale document
$doc = Document::where('tenant_id', 5)
    ->where('source', 'web_scraper')
    ->where('source_url', 'like', '%idtesto/20110%')
    ->latest()
    ->first();

if (! $doc) {
    echo 'Documento Comando Polizia Locale non trovato!'.PHP_EOL;
    exit(1);
}

echo "=== RE-INGESTION DOCUMENTO {$doc->id} ===".PHP_EOL.PHP_EOL;

echo "Documento: {$doc->title}".PHP_EOL;
echo "Source: {$doc->source}".PHP_EOL;
echo "Source URL: {$doc->source_url}".PHP_EOL;
echo PHP_EOL;

// Delete existing chunks
echo 'Deleting existing chunks...'.PHP_EOL;
$deletedCount = DocumentChunk::where('document_id', $doc->id)->delete();
echo "Deleted {$deletedCount} chunks".PHP_EOL.PHP_EOL;

// Update ingestion status
$doc->ingestion_status = 'pending';
$doc->save();

echo 'Dispatching ingestion job...'.PHP_EOL;
IngestUploadedDocumentJob::dispatch($doc->id);

echo '✅ Re-ingestion job dispatched!'.PHP_EOL;
echo PHP_EOL;
echo 'Run queue worker to process:'.PHP_EOL;
echo '  php artisan queue:work --queue=ingestion'.PHP_EOL;
echo PHP_EOL;
echo 'After processing, verify chunks with:'.PHP_EOL;
echo "  php artisan tinker --execute=\"dump(App\\Models\\DocumentChunk::where('document_id', {$doc->id})->count());\"".PHP_EOL;
echo PHP_EOL;
echo 'Inspect first chunk:'.PHP_EOL;
echo "  php artisan tinker --execute=\"echo substr(App\\Models\\DocumentChunk::where('document_id', {$doc->id})->orderBy('chunk_index')->first()->content, 0, 1000);\"".PHP_EOL;
