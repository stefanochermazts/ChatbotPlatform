<?php

namespace App\Console\Commands;

use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryFailedDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'document:retry-failed {--tenant_id= : Riprova solo per un tenant specifico} {--type= : Riprova solo file di un tipo specifico (xlsx,docx,etc)} {--limit=10 : Massimo numero di documenti da riprovare}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Riprova l\'ingestion dei documenti falliti (utile dopo fix di bug)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant_id');
        $type = $this->option('type');
        $limit = (int) $this->option('limit');
        
        $this->info("🔄 Cercando documenti con ingestion fallita...");
        
        // Query per documenti falliti
        $query = Document::where('ingestion_status', 'failed');
        
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
            $this->info("🏢 Filtro tenant: {$tenantId}");
        }
        
        if ($type) {
            $query->where('title', 'LIKE', "%.{$type}");
            $this->info("📝 Filtro tipo: {$type}");
        }
        
        $failedDocuments = $query->limit($limit)->get();
        
        if ($failedDocuments->isEmpty()) {
            $this->info("✅ Nessun documento fallito trovato con i criteri specificati.");
            return 0;
        }
        
        $this->info("📊 Trovati " . $failedDocuments->count() . " documenti da riprovare");
        
        $this->table(
            ['ID', 'Nome', 'Tenant', 'Ultimo Errore', 'Data'],
            $failedDocuments->map(function ($doc) {
                return [
                    $doc->id,
                    $doc->title,
                    $doc->tenant_id,
                    mb_substr($doc->last_error ?? 'N/A', 0, 50) . '...',
                    $doc->updated_at->format('Y-m-d H:i')
                ];
            })
        );
        
        if (!$this->confirm('Vuoi riprovare l\'ingestion di questi documenti?')) {
            $this->info("❌ Operazione annullata.");
            return 0;
        }
        
        $requeued = 0;
        $errors = 0;
        
        foreach ($failedDocuments as $document) {
            try {
                // Reset dello status prima di riprovare
                $document->update([
                    'ingestion_status' => 'pending',
                    'ingestion_progress' => 0,
                    'last_error' => null,
                ]);
                
                // Riaccoda il job
                IngestUploadedDocumentJob::dispatch($document->id);
                
                $this->info("✅ Riaccondato: {$document->title} (ID: {$document->id})");
                $requeued++;
                
            } catch (\Throwable $e) {
                $this->error("❌ Errore riaccoda {$document->title}: " . $e->getMessage());
                $errors++;
            }
        }
        
        $this->info("\n🏁 Riepilogo:");
        $this->info("✅ Riaccondati: {$requeued}");
        if ($errors > 0) {
            $this->warn("❌ Errori: {$errors}");
        }
        
        $this->info("\n💡 Suggerimenti:");
        $this->info("- Monitora la coda: php artisan queue:work");
        $this->info("- Controlla i log: tail -f storage/logs/laravel.log");
        $this->info("- Verifica progress: php artisan document:retry-failed --limit 5");
        
        return $errors > 0 ? 1 : 0;
    }
}
