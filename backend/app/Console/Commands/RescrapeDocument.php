<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Scraper\WebScraperService;
use Illuminate\Console\Command;

class RescrapeDocument extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:rescrape 
                           {document : ID del documento da ri-scrapare}
                           {--all-scraped : Ri-scrapa tutti i documenti con source_url per un tenant}
                           {--tenant= : ID tenant (richiesto se usi --all-scraped)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '🔄 Re-scraping di documenti esistenti (singoli o in batch)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $documentId = $this->argument('document');
        $allScraped = $this->option('all-scraped');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        if ($allScraped) {
            return $this->handleBatchRescrape($tenantId);
        } else {
            return $this->handleSingleRescrape((int) $documentId);
        }
    }

    /**
     * Re-scraping di un singolo documento
     */
    private function handleSingleRescrape(int $documentId): int
    {
        $this->info("🔄 Inizio re-scraping documento #{$documentId}");

        try {
            // Verifica che il documento esista
            $document = Document::find($documentId);

            if (! $document) {
                $this->error("❌ Documento #{$documentId} non trovato");

                return Command::FAILURE;
            }

            if (! $document->source_url) {
                $this->error("❌ Documento #{$documentId} non ha source_url - non può essere ri-scrapato");

                return Command::FAILURE;
            }

            $this->table(['Dettaglio', 'Valore'], [
                ['ID Documento', $document->id],
                ['Titolo', $document->title],
                ['Source URL', $document->source_url],
                ['Tenant', $document->tenant_id],
                ['KB', $document->knowledge_base_id],
                ['Ultimo scraping', $document->last_scraped_at ?? 'Mai'],
                ['Versione', $document->scrape_version ?? 1],
            ]);

            $this->newLine();

            if (! $this->confirm('Procedere con il re-scraping?')) {
                $this->info('Operazione annullata.');

                return Command::SUCCESS;
            }

            $scraperService = new WebScraperService;

            $this->newLine();
            $this->info('⏳ Avvio re-scraping...');

            $result = $scraperService->forceRescrapDocument($documentId);

            if ($result['success']) {
                $this->newLine();
                $this->info('✅ Re-scraping completato con successo!');

                $this->table(['Metrica', 'Valore'], [
                    ['Documento ID', $result['document_id']],
                    ['Message', $result['message']],
                    ['Documenti salvati', $result['result']['saved_count'] ?? 'N/A'],
                    ['Nuovi', $result['result']['stats']['new'] ?? 0],
                    ['Aggiornati', $result['result']['stats']['updated'] ?? 0],
                ]);

                return Command::SUCCESS;

            } else {
                $this->newLine();
                $this->error('❌ Re-scraping fallito: '.$result['message']);

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('💥 Errore durante il re-scraping: '.$e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Re-scraping in batch di tutti i documenti scraped per un tenant
     */
    private function handleBatchRescrape(?int $tenantId): int
    {
        if (! $tenantId) {
            $this->error('❌ --tenant è richiesto quando usi --all-scraped');

            return Command::FAILURE;
        }

        $this->info("🔄 Inizio re-scraping batch per tenant #{$tenantId}");

        try {
            // Trova tutti i documenti con source_url per questo tenant
            $documents = Document::where('tenant_id', $tenantId)
                ->whereNotNull('source_url')
                ->where('source_url', '!=', '')
                ->get();

            if ($documents->isEmpty()) {
                $this->warn("⚠️ Nessun documento con source_url trovato per tenant #{$tenantId}");

                return Command::SUCCESS;
            }

            $this->info("📄 Trovati {$documents->count()} documenti da ri-scrapare:");

            $tableData = [];
            foreach ($documents->take(10) as $doc) {
                $tableData[] = [
                    $doc->id,
                    \Illuminate\Support\Str::limit($doc->title, 40),
                    \Illuminate\Support\Str::limit($doc->source_url, 50),
                    $doc->last_scraped_at ?? 'Mai',
                ];
            }

            $this->table(['ID', 'Titolo', 'URL', 'Ultimo Scraping'], $tableData);

            if ($documents->count() > 10) {
                $this->line('   ... e altri '.($documents->count() - 10).' documenti');
            }

            $this->newLine();

            if (! $this->confirm("Procedere con il re-scraping di {$documents->count()} documenti?")) {
                $this->info('Operazione annullata.');

                return Command::SUCCESS;
            }

            $scraperService = new WebScraperService;
            $successCount = 0;
            $failureCount = 0;

            $this->newLine();
            $bar = $this->output->createProgressBar($documents->count());
            $bar->start();

            foreach ($documents as $document) {
                try {
                    $result = $scraperService->forceRescrapDocument($document->id);

                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $failureCount++;
                        $this->newLine();
                        $this->error("❌ Fallito doc #{$document->id}: ".$result['message']);
                    }

                } catch (\Exception $e) {
                    $failureCount++;
                    $this->newLine();
                    $this->error("💥 Errore doc #{$document->id}: ".$e->getMessage());
                }

                $bar->advance();

                // Rate limiting
                usleep(500000); // 0.5 secondi tra richieste
            }

            $bar->finish();
            $this->newLine(2);

            $this->info('📊 Re-scraping batch completato!');
            $this->table(['Risultato', 'Conteggio'], [
                ['✅ Successi', $successCount],
                ['❌ Fallimenti', $failureCount],
                ['📄 Totale', $documents->count()],
            ]);

            return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('💥 Errore durante re-scraping batch: '.$e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
