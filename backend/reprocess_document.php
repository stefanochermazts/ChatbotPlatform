<?php

require_once __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;

echo "🔄 RIPROCESSAMENTO CON CHUNKING CORRETTO\n";
echo "=========================================\n\n";

$doc = Document::find(1795);
echo 'Documento: '.$doc->title."\n";

echo "Configurazione chunking attuale:\n";
echo '- max_chars: '.config('rag.chunk.max_chars')."\n";
echo '- overlap_chars: '.config('rag.chunk.overlap_chars')."\n\n";

// Cancella i chunk esistenti
$deletedChunks = DB::table('document_chunks')->where('document_id', 1795)->delete();
echo "Chunks cancellati: {$deletedChunks}\n";

// Marca come pending per riprocessamento
$doc->update(['ingestion_status' => 'pending']);
echo "Documento marcato come pending\n";

// Riprocessa il documento
IngestUploadedDocumentJob::dispatch($doc->id);
echo "Job di re-ingestion schedulato\n\n";

echo "✅ COMPLETATO!\n";
echo "Ora eseguire: php artisan queue:work per processare il job\n";
