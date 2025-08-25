<?php

namespace App\Console\Commands;

use App\Services\RAG\HyDEExpander;
use App\Services\RAG\KbSearchService;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestHyDE extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rag:test-hyde {tenant_id : ID del tenant} {query : Query da testare} {--compare : Confronta risultati con e senza HyDE} {--detailed : Output dettagliato}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa HyDE (Hypothetical Document Embeddings) con query specifiche';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');
        $compare = $this->option('compare');
        $detailed = $this->option('detailed');
        
        // Verifica tenant
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato");
            return 1;
        }
        
        $this->info("🔬 Testando HyDE per tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("💬 Query: {$query}");
        $this->newLine();
        
        if ($compare) {
            return $this->runComparison($tenantId, $query, $detailed);
        } else {
            return $this->runSingleTest($tenantId, $query, $detailed);
        }
    }
    
    private function runComparison(int $tenantId, string $query, bool $detailed): int
    {
        $this->info("🔄 Confronto: Standard vs HyDE");
        $this->newLine();
        
        // Test senza HyDE
        $this->line("⚞️  🔵 Test STANDARD (senza HyDE)");
        Config::set('rag.advanced.hyde.enabled', false);
        $kbStandard = app(KbSearchService::class);
        $startTime = microtime(true);
        $standardResults = $kbStandard->retrieve($tenantId, $query, true);
        $standardTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Test con HyDE
        $this->line("⚞️  🟣 Test HyDE (con hypothetical document)");
        Config::set('rag.advanced.hyde.enabled', true);
        $hyde = app(HyDEExpander::class);
        $kbHyde = app()->makeWith(KbSearchService::class, ['hyde' => $hyde]);
        $startTime = microtime(true);
        $hydeResults = $kbHyde->retrieve($tenantId, $query, true);
        $hydeTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Confronto risultati
        $this->newLine();
        $this->info("📊 Confronto Risultati:");
        
        $this->table(
            ['Metrica', 'Standard', 'HyDE', 'Differenza'],
            [
                [
                    'Citazioni trovate',
                    count($standardResults['citations'] ?? []),
                    count($hydeResults['citations'] ?? []),
                    count($hydeResults['citations'] ?? []) - count($standardResults['citations'] ?? [])
                ],
                [
                    'Confidence',
                    number_format($standardResults['confidence'] ?? 0, 3),
                    number_format($hydeResults['confidence'] ?? 0, 3),
                    number_format(($hydeResults['confidence'] ?? 0) - ($standardResults['confidence'] ?? 0), 3)
                ],
                [
                    'Tempo (ms)',
                    $standardTime,
                    $hydeTime,
                    '+' . ($hydeTime - $standardTime)
                ]
            ]
        );
        
        // Mostra documenti ipotetico se richiesto
        if ($detailed && isset($hydeResults['debug']['hyde'])) {
            $this->newLine();
            $this->info("📝 Documento Ipotetico Generato:");
            $this->line($hydeResults['debug']['hyde']['hypothetical_document'] ?? 'N/A');
        }
        
        // Confronta le prime citazioni
        $this->newLine();
        $this->info("📚 Prime 3 Citazioni:");
        
        $standardCitations = array_slice($standardResults['citations'] ?? [], 0, 3);
        $hydeCitations = array_slice($hydeResults['citations'] ?? [], 0, 3);
        
        for ($i = 0; $i < 3; $i++) {
            $this->line("\n🕹️  Posizione " . ($i + 1) . ":");
            
            $stdCit = $standardCitations[$i] ?? null;
            $hydeCit = $hydeCitations[$i] ?? null;
            
            if ($stdCit) {
                $this->line("🔵 Standard: Doc {$stdCit['document_id']} - {$stdCit['title']} (Score: " . number_format($stdCit['score'], 3) . ")");
            } else {
                $this->line("🔵 Standard: Nessuna citazione");
            }
            
            if ($hydeCit) {
                $this->line("🟣 HyDE: Doc {$hydeCit['document_id']} - {$hydeCit['title']} (Score: " . number_format($hydeCit['score'], 3) . ")");
            } else {
                $this->line("🟣 HyDE: Nessuna citazione");
            }
            
            if ($stdCit && $hydeCit) {
                $same = $stdCit['document_id'] === $hydeCit['document_id'];
                $this->line($same ? "✅ Stesso documento" : "❌ Documenti diversi");
            }
        }
        
        return 0;
    }
    
    private function runSingleTest(int $tenantId, string $query, bool $detailed): int
    {
        // Abilita HyDE
        Config::set('rag.advanced.hyde.enabled', true);
        
        $hyde = app(HyDEExpander::class);
        $kb = app()->makeWith(KbSearchService::class, ['hyde' => $hyde]);
        
        $startTime = microtime(true);
        $results = $kb->retrieve($tenantId, $query, true);
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $this->info("✨ Risultati HyDE:");
        $this->info("Tempo totale: {$totalTime}ms");
        $this->info("Citazioni trovate: " . count($results['citations'] ?? []));
        $this->info("Confidence: " . number_format($results['confidence'] ?? 0, 3));
        
        // HyDE Debug Info
        if (isset($results['debug']['hyde'])) {
            $hyde = $results['debug']['hyde'];
            $this->newLine();
            $this->info("🔬 Debug HyDE:");
            $this->info("Status: " . ($hyde['success'] ? '✅ Success' : '❌ Failed'));
            $this->info("Processing time: " . ($hyde['processing_time_ms'] ?? 0) . "ms");
            
            if ($hyde['success'] && $detailed) {
                $this->newLine();
                $this->info("📝 Documento Ipotetico:");
                $this->line($hyde['hypothetical_document'] ?? 'N/A');
                
                $this->newLine();
                $this->info("⚙️  Pesi Embedding:");
                $weights = $hyde['weights'] ?? [];
                $this->line("Original: " . ($weights['original'] ?? 0));
                $this->line("Hypothetical: " . ($weights['hypothetical'] ?? 0));
            }
        }
        
        // Mostra citazioni
        if (!empty($results['citations'])) {
            $this->newLine();
            $this->info("📚 Citazioni:");
            foreach (array_slice($results['citations'], 0, 5) as $i => $citation) {
                $this->line(($i + 1) . ". Doc {$citation['document_id']} - {$citation['title']} (Score: " . number_format($citation['score'], 3) . ")");
            }
        }
        
        return 0;
    }
}
