<?php

namespace App\Console\Commands;

use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;

class FixStuckDocuments extends Command
{
    protected $signature = 'documents:fix-stuck 
                           {--tenant= : ID tenant specifico}
                           {--document= : ID documento specifico}
                           {--dry-run : Solo mostra documenti bloccati senza modificarli}';

    protected $description = '🩹 Ripara documenti bloccati in stato processing da più di 30 minuti';

    public function handle()
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $documentId = $this->option('document') ? (int) $this->option('document') : null;
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Ricerca documenti bloccati...');

        $query = Document::where('ingestion_status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(30)); // Bloccati da più di 30 min

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        if ($documentId) {
            $query->where('id', $documentId);
        }

        $stuckDocuments = $query->get();

        if ($stuckDocuments->isEmpty()) {
            $this->info('✅ Nessun documento bloccato trovato');

            return Command::SUCCESS;
        }

        $this->warn("⚠️ Trovati {$stuckDocuments->count()} documenti bloccati:");

        $tableData = [];
        foreach ($stuckDocuments as $doc) {
            $tableData[] = [
                $doc->id,
                $doc->tenant_id,
                \Illuminate\Support\Str::limit($doc->title, 40),
                $doc->updated_at->diffForHumans(),
                $doc->source ?? 'N/A',
            ];
        }

        $this->table(['ID', 'Tenant', 'Titolo', 'Bloccato da', 'Source'], $tableData);

        if ($dryRun) {
            $this->info('🔍 Dry run - nessuna modifica applicata');

            return Command::SUCCESS;
        }

        if (! $this->confirm('Ripristinare questi documenti a stato pending?')) {
            $this->info('Operazione annullata');

            return Command::SUCCESS;
        }

        $fixed = 0;
        $failed = 0;

        $this->newLine();
        $bar = $this->output->createProgressBar($stuckDocuments->count());
        $bar->start();

        foreach ($stuckDocuments as $document) {
            try {
                // Reset status a pending
                $document->update([
                    'ingestion_status' => 'pending',
                    'ingestion_error' => null,
                    'progress_percentage' => 0,
                ]);

                // Riavvia job di ingestion
                IngestUploadedDocumentJob::dispatch($document->id)->onQueue('ingestion');

                $fixed++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Errore riparando doc #{$document->id}: ".$e->getMessage());
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('📊 Riparazione completata!');
        $this->table(['Risultato', 'Conteggio'], [
            ['✅ Riparati', $fixed],
            ['❌ Falliti', $failed],
            ['📄 Totale', $stuckDocuments->count()],
        ]);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
