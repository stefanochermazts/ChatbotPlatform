<?php

namespace App\Console\Commands;

use App\Models\KnowledgeBase;
use App\Services\Scraper\WebScraperService;
use Illuminate\Console\Command;

class ScrapeUrl extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:single 
                           {tenant : ID del tenant}
                           {url : URL da scrapare}
                           {--force : Sovrascrive documento esistente}
                           {--kb= : ID della Knowledge Base di destinazione (opzionale)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '🎯 Scraping di un singolo URL per un tenant specifico';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = (int) $this->argument('tenant');
        $url = $this->argument('url');
        $force = $this->option('force');
        $kbId = $this->option('kb') ? (int) $this->option('kb') : null;

        // Validazioni
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error("❌ URL non valido: {$url}");

            return Command::FAILURE;
        }

        $this->info('🎯 Inizio scraping singolo URL');
        $this->table(['Parametro', 'Valore'], [
            ['Tenant ID', $tenantId],
            ['URL', $url],
            ['Force Mode', $force ? 'SÌ' : 'NO'],
            ['Knowledge Base ID', $kbId ?? 'Auto (default)'],
        ]);

        // Verifica Knowledge Base se specificata
        if ($kbId) {
            $kb = KnowledgeBase::where('id', $kbId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $kb) {
                $this->error("❌ Knowledge Base #{$kbId} non trovata per tenant #{$tenantId}");

                return Command::FAILURE;
            }

            $this->info("📚 Knowledge Base target: {$kb->name}");
        }

        try {
            $scraperService = new WebScraperService;

            $this->newLine();
            $this->info('⏳ Avvio scraping...');

            $result = $scraperService->scrapeSingleUrl($tenantId, $url, $force, $kbId);

            if ($result['success']) {
                $this->newLine();
                $this->info('✅ Scraping completato con successo!');

                $this->table(['Metrica', 'Valore'], [
                    ['URL', $result['url']],
                    ['Documenti salvati', $result['saved_count']],
                    ['Nuovi', $result['stats']['new'] ?? 0],
                    ['Aggiornati', $result['stats']['updated'] ?? 0],
                    ['Saltati', $result['stats']['skipped'] ?? 0],
                ]);

                if (isset($result['document'])) {
                    $doc = $result['document'];
                    $this->newLine();
                    $this->info('📄 Documento estratto:');
                    $this->line("   Titolo: {$doc['title']}");
                    $this->line('   Contenuto: '.strlen($doc['content']).' caratteri');
                    $this->line("   Estratto: {$doc['extracted_at']}");
                }

                return Command::SUCCESS;

            } else {
                $this->newLine();
                $this->error('❌ Scraping fallito: '.$result['message']);

                if (isset($result['existing_document'])) {
                    $existing = $result['existing_document'];
                    $this->newLine();
                    $this->warn('📄 Documento esistente trovato:');
                    $this->line("   ID: {$existing['id']}");
                    $this->line("   Titolo: {$existing['title']}");
                    $this->line("   Creato: {$existing['created_at']}");
                    $this->newLine();
                    $this->comment('💡 Usa --force per sovrascrivere il documento esistente');
                }

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $this->newLine();
            $this->error("💥 Errore durante l'esecuzione: ".$e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
