<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanOldScrapedDocuments extends Command
{
    protected $signature = 'scraper:clean-old {--tenant= : ID del tenant} {--days=30 : Giorni di retention} {--dry-run : Simulazione senza eliminare}';
    
    protected $description = 'Pulisce i documenti scraped più vecchi di N giorni';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $this->info("🧹 Pulizia documenti scraped più vecchi di {$days} giorni");
        
        if ($dryRun) {
            $this->warn("🔍 MODALITÀ DRY-RUN: Nessun file verrà eliminato");
        }

        $query = Document::where('source', 'web_scraper')
            ->where('last_scraped_at', '<', now()->subDays($days));
            
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
            $this->info("🎯 Limitato al tenant: {$tenantId}");
        }

        $oldDocuments = $query->get();
        
        if ($oldDocuments->isEmpty()) {
            $this->info("✅ Nessun documento da pulire trovato");
            return 0;
        }

        $this->info("📄 Trovati {$oldDocuments->count()} documenti da pulire");

        if (!$dryRun) {
            $bar = $this->output->createProgressBar($oldDocuments->count());
            $bar->start();
        }

        $deletedFiles = 0;
        $deletedRecords = 0;

        foreach ($oldDocuments as $document) {
            try {
                if (!$dryRun) {
                    // Elimina file se esiste
                    if ($document->path && Storage::disk('public')->exists($document->path)) {
                        Storage::disk('public')->delete($document->path);
                        $deletedFiles++;
                    }
                    
                    // Elimina record database
                    $document->delete();
                    $deletedRecords++;
                    
                    $bar->advance();
                } else {
                    $this->line("🗑️  Eliminerebbe: {$document->title} (ID: {$document->id})");
                    $deletedRecords++;
                }
                
            } catch (\Exception $e) {
                $this->error("❌ Errore eliminando documento {$document->id}: {$e->getMessage()}");
            }
        }

        if (!$dryRun) {
            $bar->finish();
            $this->newLine();
        }

        $this->info("✅ Pulizia completata:");
        $this->info("   📄 Record eliminati: {$deletedRecords}");
        if (!$dryRun) {
            $this->info("   📁 File eliminati: {$deletedFiles}");
        }

        return 0;
    }
}
