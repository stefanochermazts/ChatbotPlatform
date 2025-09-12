<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RAG\KbSearchService;
use Illuminate\Console\Command;

class TestMultiKbBoost extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:multi-kb-boost 
                          {tenant : Tenant ID}
                          {query? : Query di test (default: "orario vigili urbani")}
                          {--enable-multi-kb : Abilita automaticamente multi-KB search per il tenant}
                          {--debug : Abilita debug mode per vedere dettagli boost}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa i boost applicati in modalità Multi-KB Search';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = (int) $this->argument('tenant');
        $query = $this->argument('query') ?: 'orario vigili urbani';
        $debug = $this->option('debug');

        $this->info('🚀 TEST MULTI-KB BOOST');
        $this->newLine();

        // Verifica tenant
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato!");
            return 1;
        }

        $this->info("🏢 Tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("🔤 Query: \"{$query}\"");

        // Abilita multi-KB se richiesto
        if ($this->option('enable-multi-kb')) {
            $tenant->update(['multi_kb_search' => true]);
            $this->info("✅ Multi-KB Search abilitato per il tenant");
        }

        $this->info("🌍 Multi-KB Search: " . ($tenant->multi_kb_search ? 'ABILITATO' : 'DISABILITATO'));
        $this->newLine();

        // Test con Multi-KB DISABILITATO
        $this->info('📊 TEST 1: Multi-KB DISABILITATO');
        $tenant->update(['multi_kb_search' => false]);
        
        $kbSearchService = app(KbSearchService::class);
        $resultSingleKb = $kbSearchService->retrieve($tenantId, $query, $debug);
        
        $this->displayResults($resultSingleKb, 'Single-KB Mode');
        $this->newLine();

        // Test con Multi-KB ABILITATO
        $this->info('📊 TEST 2: Multi-KB ABILITATO (con boost)');
        $tenant->update(['multi_kb_search' => true]);
        
        $resultMultiKb = $kbSearchService->retrieve($tenantId, $query, $debug);
        
        $this->displayResults($resultMultiKb, 'Multi-KB Mode + Boost');
        $this->newLine();

        // Confronto risultati
        $this->info('🔄 CONFRONTO RISULTATI:');
        $this->compareResults($resultSingleKb, $resultMultiKb);

        return 0;
    }

    private function displayResults(array $result, string $mode): void
    {
        $citations = $result['citations'] ?? [];
        $confidence = $result['confidence'] ?? 0;

        $this->info("🎯 Modalità: {$mode}");
        $this->info("📈 Confidence: " . round($confidence * 100, 1) . "%");
        $this->info("📋 Citazioni trovate: " . count($citations));

        if (empty($citations)) {
            $this->warn("   ⚠️  Nessuna citazione trovata");
            return;
        }

        // Mostra top 3 citazioni
        $topCitations = array_slice($citations, 0, 3);
        $citationData = [];

        foreach ($topCitations as $i => $citation) {
            $docId = $citation['document_id'] ?? 'unknown';
            $score = isset($citation['score']) ? round($citation['score'], 3) : 'unknown';
            $snippet = substr($citation['snippet'] ?? $citation['chunk_text'] ?? '', 0, 60) . '...';
            $source = $citation['document_source'] ?? 'unknown';
            
            $citationData[] = [
                '#' . ($i + 1),
                "Doc {$docId}",
                $score,
                $snippet,
                $source
            ];
        }

        $this->table(
            ['Rank', 'Document', 'Score', 'Snippet', 'Source'],
            $citationData
        );

        // Debug info se abilitato
        if (isset($result['debug']) && $result['debug']) {
            $debug = $result['debug'];
            if (isset($debug['fused_top'])) {
                $this->info("🔍 Debug - Top risultati fusi:");
                foreach (array_slice($debug['fused_top'], 0, 3) as $i => $hit) {
                    $boostInfo = '';
                    if (isset($hit['boost_debug'])) {
                        $boost = $hit['boost_debug'];
                        $boostInfo = sprintf(
                            " (Original: %.3f → Boost: %.2fx → Final: %.3f)",
                            $boost['original_score'],
                            $boost['boost_multiplier'],
                            $boost['final_score']
                        );
                    }
                    $this->line(sprintf("   %d. Doc %s: %.3f%s", 
                        $i + 1, 
                        $hit['document_id'], 
                        $hit['score'], 
                        $boostInfo
                    ));
                }
            }
        }
    }

    private function compareResults(array $singleKb, array $multiKb): void
    {
        $singleCitations = $singleKb['citations'] ?? [];
        $multiCitations = $multiKb['citations'] ?? [];
        
        $singleConfidence = round(($singleKb['confidence'] ?? 0) * 100, 1);
        $multiConfidence = round(($multiKb['confidence'] ?? 0) * 100, 1);

        $this->table(
            ['Metrica', 'Single-KB', 'Multi-KB', 'Differenza'],
            [
                ['Citazioni', count($singleCitations), count($multiCitations), count($multiCitations) - count($singleCitations)],
                ['Confidence %', $singleConfidence, $multiConfidence, round($multiConfidence - $singleConfidence, 1)],
            ]
        );

        // Documenti unici trovati
        $singleDocs = collect($singleCitations)->pluck('document_id')->unique();
        $multiDocs = collect($multiCitations)->pluck('document_id')->unique();
        
        $newDocsInMulti = $multiDocs->diff($singleDocs);
        
        if ($newDocsInMulti->isNotEmpty()) {
            $this->info("✨ Documenti aggiuntivi trovati in Multi-KB: " . $newDocsInMulti->implode(', '));
        }

        // Verifica se il boost ha funzionato
        if (count($multiCitations) > count($singleCitations)) {
            $this->info("🚀 ✅ Multi-KB + Boost ha trovato più risultati!");
        } elseif ($multiConfidence > $singleConfidence) {
            $this->info("📈 ✅ Multi-KB + Boost ha migliorato la confidence!");
        } else {
            $this->warn("⚠️  Multi-KB non ha migliorato significativamente i risultati");
        }
    }
}