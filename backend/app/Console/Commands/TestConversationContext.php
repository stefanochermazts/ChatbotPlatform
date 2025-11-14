<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RAG\ConversationContextEnhancer;
use App\Services\RAG\KbSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestConversationContext extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rag:test-conversation {tenant_id : ID del tenant} {query : Query corrente} {--history= : JSON con messaggi precedenti} {--detailed : Output dettagliato}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa il sistema di context enhancement conversazionale';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');
        $historyJson = $this->option('history');
        $detailed = $this->option('detailed');

        // Verifica tenant
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato");

            return 1;
        }

        $this->info("💬 Testando Conversation Context per tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("📝 Query corrente: {$query}");
        $this->newLine();

        // Prepara messaggi conversazione
        $messages = $this->prepareMessages($query, $historyJson);

        if (count($messages) <= 1) {
            $this->warn('⚠️  Nessuna conversazione precedente fornita. Usa --history per testare il context enhancement.');
            $this->line("Esempio: --history='[{\"role\": \"user\", \"content\": \"Che orari ha la biblioteca?\"}, {\"role\": \"assistant\", \"content\": \"La biblioteca è aperta...\"}]'");
            $this->newLine();
        }

        // Test conversation enhancement
        $this->info('🧠 Testing Conversation Context Enhancement...');
        $this->testConversationEnhancement($tenantId, $query, $messages, $detailed);

        $this->newLine();
        $this->info('🔄 Testing Full RAG Pipeline with Conversation...');
        $this->testFullPipeline($tenantId, $query, $messages, $detailed);

        return 0;
    }

    private function prepareMessages(string $currentQuery, ?string $historyJson): array
    {
        $messages = [];

        if ($historyJson) {
            try {
                $history = json_decode($historyJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($history)) {
                    $messages = $history;
                }
            } catch (\JsonException $e) {
                $this->warn("⚠️  Errore parsing JSON history: {$e->getMessage()}");
            }
        }

        // Aggiungi query corrente
        $messages[] = ['role' => 'user', 'content' => $currentQuery];

        return $messages;
    }

    private function testConversationEnhancement(int $tenantId, string $query, array $messages, bool $detailed): void
    {
        // Abilita temporaneamente conversation context
        $originalConfig = config('rag.conversation.enabled');
        Config::set('rag.conversation.enabled', true);

        try {
            $enhancer = app(ConversationContextEnhancer::class);
            $result = $enhancer->enhanceQuery($query, $messages, $tenantId);

            $this->displayEnhancementResult($result, $detailed);

        } finally {
            Config::set('rag.conversation.enabled', $originalConfig);
        }
    }

    private function displayEnhancementResult(array $result, bool $detailed): void
    {
        $contextUsed = $result['context_used'] ?? false;
        $processingTime = $result['processing_time_ms'] ?? 0;

        if ($contextUsed) {
            $this->line("✅ Context enhancement eseguito con successo in {$processingTime}ms");

            if ($detailed) {
                $this->newLine();
                $this->info('🔍 Query Originale:');
                $this->line($result['original_query'] ?? '');

                $this->newLine();
                $this->info('✨ Query Arricchita:');
                $this->line($result['enhanced_query'] ?? '');

                if (! empty($result['conversation_summary'])) {
                    $this->newLine();
                    $this->info('📝 Riassunto Conversazione:');
                    $this->line($result['conversation_summary']);
                }
            }
        } else {
            $this->line('🔄 Nessun context enhancement applicato');
            if ($detailed && isset($result['original_query'])) {
                $this->line('Motivo: Conversazione troppo breve o contesto non rilevante');
            }
        }
    }

    private function testFullPipeline(int $tenantId, string $query, array $messages, bool $detailed): void
    {
        // Configura per test completo
        $originalConversationConfig = config('rag.conversation.enabled');
        Config::set('rag.conversation.enabled', true);

        try {
            $kb = app(KbSearchService::class);

            // Test senza conversazione
            $this->line('⚞️  Test SENZA conversation context:');
            Config::set('rag.conversation.enabled', false);
            $startTime = microtime(true);
            $resultWithout = $kb->retrieve($tenantId, $query, true);
            $timeWithout = round((microtime(true) - $startTime) * 1000, 2);

            // Test con conversazione
            $this->line('⚞️  Test CON conversation context:');
            Config::set('rag.conversation.enabled', true);

            // Simula la logica del ChatCompletionsController
            $enhancer = app(ConversationContextEnhancer::class);
            $conversationResult = $enhancer->enhanceQuery($query, $messages, $tenantId);
            $finalQuery = $conversationResult['context_used'] ? $conversationResult['enhanced_query'] : $query;

            $startTime = microtime(true);
            $resultWith = $kb->retrieve($tenantId, $finalQuery, true);
            $timeWith = round((microtime(true) - $startTime) * 1000, 2);

            // Confronto risultati
            $this->newLine();
            $this->info('📊 Confronto Risultati:');

            $this->table(
                ['Metrica', 'Senza Context', 'Con Context', 'Differenza'],
                [
                    [
                        'Citazioni trovate',
                        count($resultWithout['citations'] ?? []),
                        count($resultWith['citations'] ?? []),
                        count($resultWith['citations'] ?? []) - count($resultWithout['citations'] ?? []),
                    ],
                    [
                        'Confidence',
                        number_format($resultWithout['confidence'] ?? 0, 3),
                        number_format($resultWith['confidence'] ?? 0, 3),
                        number_format(($resultWith['confidence'] ?? 0) - ($resultWithout['confidence'] ?? 0), 3),
                    ],
                    [
                        'Tempo (ms)',
                        $timeWithout,
                        $timeWith,
                        '+'.($timeWith - $timeWithout),
                    ],
                ]
            );

            if ($detailed) {
                $this->displayDetailedComparison($resultWithout, $resultWith, $conversationResult);
            }

        } finally {
            Config::set('rag.conversation.enabled', $originalConversationConfig);
        }
    }

    private function displayDetailedComparison(array $resultWithout, array $resultWith, array $conversationResult): void
    {
        $this->newLine();
        $this->info('📚 Confronto Prime 3 Citazioni:');

        $citationsWithout = array_slice($resultWithout['citations'] ?? [], 0, 3);
        $citationsWith = array_slice($resultWith['citations'] ?? [], 0, 3);

        for ($i = 0; $i < 3; $i++) {
            $this->line("\n🕹️  Posizione ".($i + 1).':');

            $citWithout = $citationsWithout[$i] ?? null;
            $citWith = $citationsWith[$i] ?? null;

            if ($citWithout) {
                $this->line("🔵 Senza Context: Doc {$citWithout['document_id']} - {$citWithout['title']} (Score: ".number_format($citWithout['score'], 3).')');
            } else {
                $this->line('🔵 Senza Context: Nessuna citazione');
            }

            if ($citWith) {
                $this->line("💬 Con Context: Doc {$citWith['document_id']} - {$citWith['title']} (Score: ".number_format($citWith['score'], 3).')');
            } else {
                $this->line('💬 Con Context: Nessuna citazione');
            }

            if ($citWithout && $citWith) {
                $same = $citWithout['document_id'] === $citWith['document_id'];
                $this->line($same ? '✅ Stesso documento' : '✨ Documento diverso (potenzialmente migliore)');
            }
        }

        if ($conversationResult['context_used'] ?? false) {
            $this->newLine();
            $this->info('🧠 Query Enhancement Details:');
            $this->line("Original: {$conversationResult['original_query']}");
            $this->line("Enhanced: {$conversationResult['enhanced_query']}");
            if (! empty($conversationResult['conversation_summary'])) {
                $this->line("Summary: {$conversationResult['conversation_summary']}");
            }
        }
    }
}
