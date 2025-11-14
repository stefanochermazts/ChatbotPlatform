<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RAG\KbSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class TestRagComparison extends Command
{
    protected $signature = 'rag:test-comparison {tenant} {query}';

    protected $description = 'Test e confronta RAG Tester vs Chatbot per una query specifica';

    public function handle()
    {
        $tenantId = $this->argument('tenant');
        $query = $this->argument('query');

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant $tenantId non trovato");

            return 1;
        }

        $this->info('=== TEST COMPARATIVO RAG ===');
        $this->info("Tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("Query: {$query}");
        $this->info('Multi-KB: '.($tenant->multi_kb_search ? 'ABILITATO' : 'DISABILITATO'));
        $this->newLine();

        // Test 1: Configurazione RAG Tester (HyDE abilitato)
        $this->info('🔬 TEST 1: Configurazione RAG Tester');
        $this->info('════════════════════════════════════');

        Config::set('rag.advanced.hyde.enabled', true);
        Config::set('rag.reranker.driver', 'llm');

        $kb1 = app(KbSearchService::class);
        $result1 = $kb1->retrieve($tenantId, $query, true);

        $this->displayResults($result1, 'RAG Tester');

        // Test 2: Configurazione Chatbot (HyDE disabilitato)
        $this->newLine();
        $this->info('🤖 TEST 2: Configurazione Chatbot');
        $this->info('═════════════════════════════════');

        Config::set('rag.advanced.hyde.enabled', false);
        Config::set('rag.reranker.driver', 'embedding');
        Config::set('rag.answer.min_citations', 1);
        Config::set('rag.answer.min_confidence', 0.05);
        Config::set('rag.answer.force_if_has_citations', true);

        $kb2 = app(KbSearchService::class);
        $result2 = $kb2->retrieve($tenantId, $query, true);

        $this->displayResults($result2, 'Chatbot');

        // Confronto risultati
        $this->newLine();
        $this->info('📊 CONFRONTO RISULTATI');
        $this->info('═════════════════════');

        $kb1Selected = $result1['debug']['selected_kb']['kb_name'] ?? 'N/A';
        $kb2Selected = $result2['debug']['selected_kb']['kb_name'] ?? 'N/A';

        if ($kb1Selected !== $kb2Selected) {
            $this->error('⚠️  KB DIVERSE SELEZIONATE!');
            $this->error("   RAG Tester: {$kb1Selected}");
            $this->error("   Chatbot: {$kb2Selected}");
        } else {
            $this->info("✅ Stessa KB selezionata: {$kb1Selected}");
        }

        // Confronta prima citazione
        if (! empty($result1['citations']) && ! empty($result2['citations'])) {
            $doc1 = $result1['citations'][0]['title'] ?? 'N/A';
            $doc2 = $result2['citations'][0]['title'] ?? 'N/A';

            if ($doc1 !== $doc2) {
                $this->error('⚠️  DOCUMENTI DIVERSI!');
                $this->error("   RAG Tester primo doc: {$doc1}");
                $this->error("   Chatbot primo doc: {$doc2}");
            } else {
                $this->info("✅ Stesso documento principale: {$doc1}");
            }
        }

        // Log dettagliato per analisi
        Log::info('RAG Comparison Complete', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'rag_tester' => [
                'kb_selected' => $kb1Selected,
                'citations' => count($result1['citations']),
                'confidence' => $result1['confidence'],
                'first_doc' => $result1['citations'][0]['title'] ?? null,
            ],
            'chatbot' => [
                'kb_selected' => $kb2Selected,
                'citations' => count($result2['citations']),
                'confidence' => $result2['confidence'],
                'first_doc' => $result2['citations'][0]['title'] ?? null,
            ],
            'match' => $kb1Selected === $kb2Selected,
        ]);

        return 0;
    }

    private function displayResults($result, $context)
    {
        $this->info('KB selezionata: '.($result['debug']['selected_kb']['kb_name'] ?? 'N/A'));
        $this->info('KB ID: '.($result['debug']['selected_kb']['knowledge_base_id'] ?? 'N/A'));
        $this->info('Motivo: '.($result['debug']['selected_kb']['reason'] ?? 'N/A'));
        $this->info('Citazioni trovate: '.count($result['citations']));
        $this->info('Confidenza: '.round($result['confidence'], 3));

        if (! empty($result['citations'])) {
            $this->newLine();
            $this->info('Prime 3 citazioni:');
            foreach (array_slice($result['citations'], 0, 3) as $i => $citation) {
                $this->info(($i + 1).'. '.($citation['title'] ?? 'N/A'));
                $this->info('   URL: '.($citation['url'] ?? 'N/A'));
                $this->info('   Score: '.($citation['score'] ?? 'N/A'));
                $this->info('   Snippet: '.substr($citation['snippet'] ?? '', 0, 80).'...');
            }
        }
    }
}
