<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\Api\ChatCompletionsController;
use App\Services\RAG\KbSearchService;

class TestSmartSourceSelection extends Command
{
    protected $signature = 'test:smart-source {tenant_id} {query}';
    protected $description = 'Test the new smart source selection algorithm';

    public function handle()
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');

        $this->info("🧪 Testing Smart Source Selection");
        $this->line("Tenant ID: {$tenantId}");
        $this->line("Query: {$query}");
        $this->line("");

        try {
            $ragService = app(KbSearchService::class);
            $result = $ragService->retrieve($tenantId, $query, true);
            
            $citations = $result['citations'] ?? [];
            $confidence = $result['confidence'] ?? 0;

            $this->info("📋 Citations found: " . count($citations));
            $this->info("📊 Confidence: " . round($confidence, 3));

            if (empty($citations)) {
                $this->warn("No citations found");
                return;
            }

            // Test dell'algoritmo smart source usando la stessa logica del controller
            $controller = app(ChatCompletionsController::class);
            $reflection = new \ReflectionClass($controller);
            
            // Accedi al metodo privato per testarlo
            $method = $reflection->getMethod('getBestSourceUrl');
            $method->setAccessible(true);
            $bestSourceUrl = $method->invoke($controller, $citations);

            $this->line("");
            $this->info("🎯 SMART SOURCE RESULT:");
            $this->line("Selected URL: " . ($bestSourceUrl ?: 'NONE'));

            $this->line("");
            $this->info("📝 CITATIONS DETAILS:");
            
            foreach ($citations as $i => $citation) {
                $this->line(($i + 1) . ". " . ($citation['title'] ?? 'No title'));
                $this->line("   Score: " . round($citation['score'] ?? 0, 3));
                $this->line("   Source URL: " . ($citation['document_source_url'] ?? 'N/A'));
                $this->line("   Content length: " . mb_strlen($citation['chunk_text'] ?? $citation['snippet'] ?? ''));
                $this->line("   Snippet: " . mb_substr($citation['snippet'] ?? '', 0, 120) . '...');
                
                // Test individual score calculation
                $smartMethod = $reflection->getMethod('calculateSmartSourceScore');
                $smartMethod->setAccessible(true);
                $smartScore = $smartMethod->invoke($controller, $citation, $citations);
                
                $this->line("   🧮 Smart Score: " . round($smartScore, 4));
                $this->line("");
            }

            // Comparison: old vs new algorithm
            $this->line("");
            $this->info("🔄 ALGORITHM COMPARISON:");
            
            // Old algorithm (highest RAG score)
            $oldBest = null;
            $oldBestScore = -1;
            foreach ($citations as $citation) {
                $score = (float) ($citation['score'] ?? 0.0);
                if ($score > $oldBestScore && !empty($citation['document_source_url'])) {
                    $oldBestScore = $score;
                    $oldBest = $citation;
                }
            }
            
            $this->line("OLD (highest RAG score): " . ($oldBest['document_source_url'] ?? 'NONE'));
            $this->line("NEW (smart algorithm):   " . ($bestSourceUrl ?: 'NONE'));
            
            if (($oldBest['document_source_url'] ?? null) !== $bestSourceUrl) {
                $this->warn("⚠️ ALGORITHMS DIFFER - Smart selection changed the result!");
            } else {
                $this->info("✅ Both algorithms selected the same source");
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
        }
    }
}
