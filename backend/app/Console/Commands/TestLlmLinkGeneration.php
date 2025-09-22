<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RAG\KbSearchService;
use App\Services\LLM\OpenAIChatService;
use Illuminate\Console\Command;

class TestLlmLinkGeneration extends Command
{
    protected $signature = 'test:llm-links 
                          {tenant_id : ID del tenant}
                          {query : Query da testare}';

    protected $description = 'Testa la generazione di link da parte dell\'LLM per identificare troncamenti';

    public function handle(KbSearchService $kb, OpenAIChatService $chat): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato");
            return 1;
        }

        $this->info("🤖 TEST GENERAZIONE LINK LLM");
        $this->info("Tenant: {$tenant->name}");
        $this->info("Query: {$query}");
        $this->newLine();

        // Esegui RAG
        $result = $kb->retrieve($tenantId, $query, false);
        $citations = $result['citations'] ?? [];

        if (empty($citations)) {
            $this->warn("⚠️  Nessuna citazione trovata");
            return 0;
        }

        // Costruisci contesto come fa il controller
        $contextText = $this->buildContext($citations);
        
        $this->info("📝 CONTESTO GENERATO:");
        $this->line("Lunghezza: " . strlen($contextText) . " caratteri");
        $this->line("Anteprima (primi 300 chars):");
        $this->line(substr($contextText, 0, 300) . "...");
        $this->newLine();

        // Crea payload per LLM con diverse configurazioni max_tokens
        $testCases = [
            ['max_tokens' => 500, 'name' => 'Limitato (500)'],
            ['max_tokens' => 1000, 'name' => 'Standard (1000)'],
            ['max_tokens' => 1500, 'name' => 'Esteso (1500)'],
        ];

        foreach ($testCases as $testCase) {
            $this->testLlmOutput($chat, $tenant, $query, $contextText, $testCase);
        }

        return 0;
    }

    private function buildContext(array $citations): string
    {
        $contextParts = [];
        
        foreach ($citations as $citation) {
            $title = $citation['title'] ?? 'N/A';
            $content = $citation['snippet'] ?? '';
            $sourceUrl = $citation['document_source_url'] ?? null;
            
            $sourceInfo = $sourceUrl ? "\n[Fonte: {$sourceUrl}]" : '';
            $contextParts[] = "[{$title}]\n{$content}{$sourceInfo}";
        }
        
        return "\n\nContesto (estratti rilevanti):\n" . implode("\n\n---\n\n", $contextParts);
    }

    private function testLlmOutput(OpenAIChatService $chat, Tenant $tenant, string $query, string $contextText, array $testCase): void
    {
        $this->line("┌" . str_repeat("─", 78) . "┐");
        $this->line("│ " . str_pad("TEST: " . $testCase['name'], 76) . " │");
        $this->line("└" . str_repeat("─", 78) . "┘");

        $systemPrompt = $tenant->custom_system_prompt ?: 
            'Rispondi usando SOLO le informazioni fornite nel contesto. Usa il formato markdown corretto per i link: [Testo](URL completo)';

        $payload = [
            'model' => 'gpt-4o-mini',
            'max_tokens' => $testCase['max_tokens'],
            'temperature' => 0.2,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Domanda: {$query}{$contextText}"]
            ]
        ];

        try {
            $result = $chat->chatCompletions($payload);
            $content = $result['choices'][0]['message']['content'] ?? '';
            $usage = $result['usage'] ?? [];

            $this->info("📊 Statistiche:");
            $this->line("• Lunghezza risposta: " . strlen($content) . " caratteri");
            $this->line("• Tokens utilizzati: " . ($usage['total_tokens'] ?? 'N/A'));
            $this->line("• Completion tokens: " . ($usage['completion_tokens'] ?? 'N/A'));
            $this->line("• Finish reason: " . ($result['choices'][0]['finish_reason'] ?? 'N/A'));
            
            // Analizza link nella risposta
            $this->analyzeLinksInResponse($content);
            
            $this->newLine();
            $this->info("📝 RISPOSTA COMPLETA:");
            $this->line($content);
            $this->newLine();
            
        } catch (\Exception $e) {
            $this->error("❌ Errore: " . $e->getMessage());
        }
    }

    private function analyzeLinksInResponse(string $content): void
    {
        // Trova link markdown
        preg_match_all('/\[([^\]]+)\]\(([^)]*)\)/', $content, $matches, PREG_SET_ORDER);
        
        $this->info("🔗 Analisi link:");
        $this->line("• Link trovati: " . count($matches));
        
        foreach ($matches as $i => $match) {
            $text = $match[1];
            $url = $match[2];
            $isComplete = str_ends_with($url, ')') || filter_var($url, FILTER_VALIDATE_URL);
            $status = $isComplete ? '✅' : '❌';
            
            $this->line("  " . ($i + 1) . ". {$status} [{$text}]({$url})");
            
            if (!$isComplete) {
                $this->line("     ⚠️  URL incompleto o malformato");
            }
        }
    }
}
