<?php

namespace App\Console\Commands;

use App\Services\RAG\KbSearchService;
use App\Services\RAG\HyDEExpander;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestLLMReranking extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rag:test-llm-reranking {tenant_id : ID del tenant} {query : Query da testare} {--compare : Confronta tutti i reranker disponibili} {--with-hyde : Combina con HyDE} {--detailed : Output dettagliato}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa LLM-as-a-Judge Reranking e confronta con altri metodi';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');
        $compare = $this->option('compare');
        $withHyde = $this->option('with-hyde');
        $detailed = $this->option('detailed');
        
        // Verifica tenant
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato");
            return 1;
        }
        
        $this->info("🤖 Testando LLM-as-a-Judge Reranking per tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("💬 Query: {$query}");
        if ($withHyde) {
            $this->info("🔬 Con HyDE abilitato");
        }
        $this->newLine();
        
        if ($compare) {
            return $this->runComparison($tenantId, $query, $withHyde, $detailed);
        } else {
            return $this->runSingleTest($tenantId, $query, 'llm', $withHyde, $detailed);
        }
    }
    
    private function runComparison(int $tenantId, string $query, bool $withHyde, bool $detailed): int
    {
        $this->info("🏆 Confronto Reranker: Embedding vs LLM vs Cohere");
        $this->newLine();
        
        $rerankers = ['embedding', 'llm', 'cohere'];
        $results = [];
        
        foreach ($rerankers as $driver) {
            $this->line("⚞️  Testando {$driver} reranker...");
            
            $startTime = microtime(true);
            $result = $this->runTestWithDriver($tenantId, $query, $driver, $withHyde);
            $endTime = microtime(true);
            
            $results[$driver] = [
                'citations' => count($result['citations'] ?? []),
                'confidence' => $result['confidence'] ?? 0,
                'time_ms' => round(($endTime - $startTime) * 1000, 2),
                'debug' => $result['debug'] ?? [],
                'top_citations' => array_slice($result['citations'] ?? [], 0, 3)
            ];
            
            if (isset($result['error'])) {
                $results[$driver]['error'] = $result['error'];
                $this->warn("   ⚠️  Errore: {$result['error']}");
            }
        }
        
        // Tabella comparativa
        $this->newLine();
        $this->info("📊 Confronto Risultati:");
        
        $tableData = [];
        foreach ($rerankers as $driver) {
            $result = $results[$driver];
            $tableData[] = [
                ucfirst($driver),
                $result['citations'],
                number_format($result['confidence'], 3),
                $result['time_ms'] . 'ms',
                isset($result['error']) ? '❌ Error' : '✅ OK'
            ];
        }
        
        $this->table(
            ['Reranker', 'Citazioni', 'Confidence', 'Tempo', 'Status'],
            $tableData
        );
        
        // Confronto top citazioni
        if ($detailed) {
            $this->newLine();
            $this->info("📚 Confronto Prime 3 Citazioni:");
            
            for ($i = 0; $i < 3; $i++) {
                $this->line("\n🕹️  Posizione " . ($i + 1) . ":");
                
                foreach ($rerankers as $driver) {
                    $citation = $results[$driver]['top_citations'][$i] ?? null;
                    $icon = match($driver) {
                        'embedding' => '🔵',
                        'llm' => '🤖',
                        'cohere' => '🟠',
                        default => '⭕'
                    };
                    
                    if ($citation) {
                        $score = number_format($citation['score'], 3);
                        $this->line("{$icon} {$driver}: Doc {$citation['document_id']} - {$citation['title']} (Score: {$score})");
                    } else {
                        $this->line("{$icon} {$driver}: Nessuna citazione");
                    }
                }
            }
        }
        
        // Analisi LLM scores se disponibili
        if (isset($results['llm']['debug']['reranking']) && $detailed) {
            $this->newLine();
            $this->info("🤖 Analisi LLM Scores:");
            
            $reranking = $results['llm']['debug']['reranking'];
            $topCandidates = $reranking['top_candidates'] ?? [];
            
            if (!empty($topCandidates)) {
                $scores = array_column($topCandidates, 'llm_score');
                $avgScore = array_sum($scores) / count($scores);
                $maxScore = max($scores);
                $minScore = min($scores);
                
                $this->line("Score medio: " . number_format($avgScore, 1));
                $this->line("Score massimo: {$maxScore}/100");
                $this->line("Score minimo: {$minScore}/100");
                
                $excellent = count(array_filter($scores, fn($s) => $s >= 80));
                $good = count(array_filter($scores, fn($s) => $s >= 60 && $s < 80));
                $poor = count(array_filter($scores, fn($s) => $s < 40));
                
                $this->line("Distribuzione: {$excellent} excellent, {$good} good, {$poor} poor");
            }
        }
        
        return 0;
    }
    
    private function runSingleTest(int $tenantId, string $query, string $driver, bool $withHyde, bool $detailed): int
    {
        $result = $this->runTestWithDriver($tenantId, $query, $driver, $withHyde);
        
        if (isset($result['error'])) {
            $this->error("❌ Errore: {$result['error']}");
            return 1;
        }
        
        $this->info("✨ Risultati {$driver} reranking:");
        $this->info("Citazioni trovate: " . count($result['citations'] ?? []));
        $this->info("Confidence: " . number_format($result['confidence'] ?? 0, 3));
        
        // Debug specifico per LLM
        if ($driver === 'llm' && isset($result['debug']['reranking'])) {
            $reranking = $result['debug']['reranking'];
            $this->newLine();
            $this->info("🤖 Debug LLM Reranking:");
            $this->info("Driver: {$reranking['driver']}");
            $this->info("Input candidates: {$reranking['input_candidates']}");
            $this->info("Output candidates: {$reranking['output_candidates']}");
            
            if ($detailed && !empty($reranking['top_candidates'])) {
                $this->newLine();
                $this->info("📊 Top 5 LLM Scores:");
                
                foreach (array_slice($reranking['top_candidates'], 0, 5) as $i => $candidate) {
                    $score = $candidate['llm_score'] ?? 0;
                    $docId = $candidate['document_id'] ?? 'N/A';
                    $chunkId = $candidate['chunk_index'] ?? 'N/A';
                    $text = mb_substr($candidate['text'] ?? '', 0, 80);
                    
                    $scoreColor = $score >= 80 ? 'green' : ($score >= 60 ? 'yellow' : 'red');
                    $this->line(($i + 1) . ". Doc {$docId}.{$chunkId} - Score: {$score}/100");
                    $this->line("   {$text}...");
                }
            }
        }
        
        // Mostra citazioni
        if (!empty($result['citations'])) {
            $this->newLine();
            $this->info("📚 Citazioni:");
            foreach (array_slice($result['citations'], 0, 5) as $i => $citation) {
                $this->line(($i + 1) . ". Doc {$citation['document_id']} - {$citation['title']} (Score: " . number_format($citation['score'], 3) . ")");
            }
        }
        
        return 0;
    }
    
    private function runTestWithDriver(int $tenantId, string $query, string $driver, bool $withHyde): array
    {
        // Salva configurazioni originali
        $originalReranker = config('rag.reranker.driver');
        $originalHyde = config('rag.advanced.hyde.enabled');
        
        try {
            // Applica configurazioni temporanee
            Config::set('rag.reranker.driver', $driver);
            
            if ($withHyde) {
                Config::set('rag.advanced.hyde.enabled', true);
            }
            
            // Crea servizio
            $kb = app(KbSearchService::class);
            if ($withHyde) {
                $hyde = app(HyDEExpander::class);
                $kb = app()->makeWith(KbSearchService::class, ['hyde' => $hyde]);
            }
            
            return $kb->retrieve($tenantId, $query, true);
            
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        } finally {
            // Ripristina configurazioni
            Config::set('rag.reranker.driver', $originalReranker);
            Config::set('rag.advanced.hyde.enabled', $originalHyde);
        }
    }
}
