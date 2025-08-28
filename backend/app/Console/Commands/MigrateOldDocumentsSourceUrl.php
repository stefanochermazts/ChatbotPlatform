<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateOldDocumentsSourceUrl extends Command
{
    protected $signature = 'scraper:migrate-source-urls 
                           {--dry-run : Mostra cosa verrebbe fatto senza modificare nulla}
                           {--tenant= : Migra solo documenti di un tenant specifico}';

    protected $description = 'Migra documenti vecchi del web scraper aggiungendo source_url basandosi sui metadati nel contenuto';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $tenantId = $this->option('tenant');
        
        $this->info('🔧 Migrazione source_url per documenti web scraper');
        $this->info('=============================================');
        
        if ($isDryRun) {
            $this->warn('⚠️  MODALITÀ DRY-RUN: Nessuna modifica verrà applicata');
        }
        
        // Query base per documenti vecchi senza source_url
        $query = Document::where('source', 'web_scraper')
            ->whereNull('source_url');
            
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
            $this->info("🏢 Limitato al tenant ID: $tenantId");
        }
        
        $documents = $query->get();
        
        if ($documents->isEmpty()) {
            $this->info('✅ Nessun documento da migrare trovato');
            return 0;
        }
        
        $this->info("📄 Trovati {$documents->count()} documenti da migrare");
        $this->newLine();
        
        $migratedCount = 0;
        $errorCount = 0;
        
        foreach ($documents as $document) {
            try {
                $sourceUrl = $this->extractSourceUrlFromDocument($document);
                
                if ($sourceUrl) {
                    $this->line("📋 ID {$document->id}: {$document->title}");
                    $this->line("   🔗 URL trovato: $sourceUrl");
                    
                    if (!$isDryRun) {
                        $document->update(['source_url' => $sourceUrl]);
                        $this->line("   ✅ Migrato");
                    } else {
                        $this->line("   🔍 [DRY-RUN] Verrebbe migrato");
                    }
                    
                    $migratedCount++;
                } else {
                    $this->line("❌ ID {$document->id}: Impossibile estrarre URL");
                    $errorCount++;
                }
                
            } catch (\Exception $e) {
                $this->error("💥 Errore ID {$document->id}: " . $e->getMessage());
                $errorCount++;
            }
        }
        
        $this->newLine();
        $this->info("📊 RISULTATI:");
        $this->info("✅ Migrati: $migratedCount");
        $this->info("❌ Errori: $errorCount");
        
        if ($isDryRun && $migratedCount > 0) {
            $this->newLine();
            $this->info("🚀 Per applicare le modifiche, riesegui senza --dry-run:");
            $command = "php artisan scraper:migrate-source-urls";
            if ($tenantId) {
                $command .= " --tenant=$tenantId";
            }
            $this->line("   $command");
        }
        
        return 0;
    }
    
    /**
     * Estrae l'URL originale dal contenuto Markdown del documento
     */
    private function extractSourceUrlFromDocument(Document $document): ?string
    {
        if (!$document->path || !Storage::disk('public')->exists($document->path)) {
            return null;
        }
        
        try {
            $content = Storage::disk('public')->get($document->path);
            
            // Cerca pattern "**URL:** https://..."
            if (preg_match('/\*\*URL:\*\*\s+(https?:\/\/[^\s\n]+)/i', $content, $matches)) {
                return trim($matches[1]);
            }
            
            // Fallback: cerca qualsiasi URL nel contenuto
            if (preg_match('/(https?:\/\/[^\s\n\)]+)/i', $content, $matches)) {
                $url = trim($matches[1]);
                
                // Filtra URL comuni che non sono l'URL sorgente
                $excludePatterns = [
                    '/privacy/',
                    '/cookie/',
                    '/facebook\.com/',
                    '/youtube\.com/',
                    '/twitter\.com/',
                    '/instagram\.com/',
                ];
                
                $skip = false;
                foreach ($excludePatterns as $pattern) {
                    if (preg_match($pattern, $url)) {
                        $skip = true;
                        break;
                    }
                }
                
                if (!$skip) {
                    return $url; // Restituisce solo se l'URL non è da escludere
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            $this->error("Errore lettura file {$document->path}: " . $e->getMessage());
            return null;
        }
    }
}
