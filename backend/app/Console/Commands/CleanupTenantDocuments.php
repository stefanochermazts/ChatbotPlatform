<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CleanupTenantDocuments extends Command
{
    protected $signature = 'documents:cleanup-tenant {tenant_id : ID del tenant da pulire}';

    protected $description = '🧹 Pulizia completa di tutti i documenti per un tenant specifico';

    public function handle()
    {
        $tenantId = (int) $this->argument('tenant_id');

        $this->info("🧹 Pulizia completa documenti per tenant {$tenantId}");

        // 1. Verifica documenti esistenti
        $docs = Document::where('tenant_id', $tenantId)->get(['id', 'title', 'path']);

        if ($docs->isEmpty()) {
            $this->info('✅ Nessun documento da eliminare');

            return Command::SUCCESS;
        }

        $this->warn("Trovati {$docs->count()} documenti da eliminare:");

        $tableData = [];
        foreach ($docs->take(10) as $doc) {
            $tableData[] = [
                $doc->id,
                \Illuminate\Support\Str::limit($doc->title, 50),
            ];
        }

        $this->table(['ID', 'Titolo'], $tableData);

        if ($docs->count() > 10) {
            $this->line('... e altri '.($docs->count() - 10).' documenti');
        }

        if (! $this->confirm('Procedere con la cancellazione completa?')) {
            $this->info('Operazione annullata');

            return Command::SUCCESS;
        }

        $this->newLine();

        // 2. Elimina chunks
        $chunkCount = DocumentChunk::whereIn('document_id', $docs->pluck('id'))->count();
        $this->info("Eliminazione {$chunkCount} chunks...");
        DocumentChunk::whereIn('document_id', $docs->pluck('id'))->delete();
        $this->info('✅ Chunks eliminati');

        // 3. Elimina file fisici
        $this->info('Eliminazione file fisici...');
        $fileCount = 0;
        foreach ($docs as $doc) {
            if ($doc->path && Storage::disk('public')->exists($doc->path)) {
                Storage::disk('public')->delete($doc->path);
                $fileCount++;
            }
        }
        $this->info("✅ {$fileCount} file fisici eliminati");

        // 4. Elimina documenti dal database
        $this->info('Eliminazione documenti dal database...');
        Document::where('tenant_id', $tenantId)->delete();
        $this->info('✅ Documenti eliminati dal database');

        // 5. Pulizia cache
        $this->info('Pulizia cache...');
        Cache::flush();
        $this->info('✅ Cache pulita');

        // 6. Verifica finale
        $remainingDocs = Document::where('tenant_id', $tenantId)->count();

        $this->newLine();
        $this->info('=== VERIFICA FINALE ===');
        $this->info("Documenti rimanenti: {$remainingDocs}");

        if ($remainingDocs == 0) {
            $this->info('🎉 PULIZIA COMPLETATA! Ora puoi rifare lo scraping.');

            return Command::SUCCESS;
        } else {
            $this->error('⚠️ Alcuni documenti non sono stati eliminati. Controlla manualmente.');

            return Command::FAILURE;
        }
    }
}
