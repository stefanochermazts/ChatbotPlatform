<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIChatService;
use Illuminate\Support\Facades\Log;

class ConversationContextEnhancer
{
    public function __construct(
        private readonly OpenAIChatService $llm
    ) {}
    
    /**
     * Arricchisce la query corrente con il contesto conversazionale
     */
    public function enhanceQuery(string $currentQuery, array $conversationHistory, int $tenantId): array
    {
        $startTime = microtime(true);
        
        // Estrai contesto rilevante dalla conversazione
        $contextSummary = $this->extractConversationContext($conversationHistory);
        
        if (empty($contextSummary)) {
            // Nessun contesto utile, usa query originale
            return [
                'enhanced_query' => $currentQuery,
                'original_query' => $currentQuery,
                'context_used' => false,
                'conversation_summary' => null,
                'processing_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        }
        
        // Genera query arricchita con contesto
        $enhancedQuery = $this->generateContextualQuery($currentQuery, $contextSummary);
        
        $endTime = microtime(true);
        
        $result = [
            'enhanced_query' => $enhancedQuery,
            'original_query' => $currentQuery,
            'context_used' => true,
            'conversation_summary' => $contextSummary,
            'processing_time_ms' => round(($endTime - $startTime) * 1000, 2),
        ];
        
        Log::info('conversation.context_enhanced', [
            'tenant_id' => $tenantId,
            'original_length' => mb_strlen($currentQuery),
            'enhanced_length' => mb_strlen($enhancedQuery),
            'context_length' => mb_strlen($contextSummary),
            'processing_time_ms' => $result['processing_time_ms'],
        ]);
        
        return $result;
    }
    
    /**
     * Estrae contesto rilevante dalla storia conversazionale
     */
    private function extractConversationContext(array $messages): ?string
    {
        if (count($messages) <= 1) {
            return null; // Solo il messaggio corrente
        }
        
        // Filtra e pulisci messaggi
        $relevantMessages = $this->filterRelevantMessages($messages);
        
        if (count($relevantMessages) < 1) {
            return null;
        }
        
        // Crea summary del contesto conversazionale
        return $this->summarizeConversation($relevantMessages);
    }
    
    /**
     * Filtra messaggi rilevanti (esclude system, tool calls, etc.)
     */
    private function filterRelevantMessages(array $messages): array
    {
        $filtered = [];
        $maxMessages = config('rag.conversation.max_history_messages', 10);
        
        // Gestione migliorata per conversazioni user-only brevi
        $totalMessages = count($messages);
        if ($totalMessages <= 1) {
            $recentMessages = [];
        } else {
            // Prendi tutti i messaggi tranne l'ultimo (query corrente), limitando a maxMessages
            $startIndex = max(0, $totalMessages - $maxMessages - 1);
            $recentMessages = array_slice($messages, $startIndex, $totalMessages - 1 - $startIndex);
        }
        
        foreach ($recentMessages as $message) {
            $role = $message['role'] ?? '';
            $content = trim($message['content'] ?? '');
            
            // Includi solo user e assistant, escludi system e tool
            if (in_array($role, ['user', 'assistant']) && !empty($content)) {
                // Escludi messaggi che sembrano essere context injection
                if (!str_starts_with($content, 'Contesto della knowledge base')) {
                    $filtered[] = [
                        'role' => $role,
                        'content' => $this->truncateMessage($content, 200)
                    ];
                }
            }
        }
        
        return $filtered;
    }
    
    /**
     * Riassume la conversazione per creare contesto
     */
    private function summarizeConversation(array $messages): ?string
    {
        if (empty($messages)) {
            return null;
        }
        
        $config = config('rag.conversation', []);
        $maxSummaryLength = $config['max_summary_length'] ?? 300;
        
        // Per conversazioni brevi, concatena direttamente
        if (count($messages) <= 3) {
            $context = '';
            foreach ($messages as $msg) {
                $speaker = $msg['role'] === 'user' ? 'Utente' : 'Assistente';
                $context .= "{$speaker}: {$msg['content']}\n";
            }
            return trim($context);
        }
        
        // Per conversazioni lunghe, usa LLM per summarization
        return $this->llmSummarizeConversation($messages, $maxSummaryLength);
    }
    
    /**
     * Usa LLM per riassumere conversazione lunga
     */
    private function llmSummarizeConversation(array $messages, int $maxLength): ?string
    {
        try {
            $conversationText = '';
            foreach ($messages as $msg) {
                $speaker = $msg['role'] === 'user' ? 'U' : 'A';
                $conversationText .= "{$speaker}: {$msg['content']}\n";
            }
            
            $prompt = "Riassumi questa conversazione in massimo {$maxLength} caratteri, " .
                     "mantenendo i temi principali e le informazioni rilevanti:\n\n" .
                     $conversationText . "\n\nRiassunto:";
            
            $config = config('rag.conversation', []);
            $model = $config['summary_model'] ?? 'gpt-4o-mini';
            
            $payload = [
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => $prompt
                ]],
                'max_tokens' => (int) ($maxLength / 2), // Stima conservativa
                'temperature' => 0.1,
            ];
            
            $response = $this->llm->chatCompletions($payload);
            $summary = trim($response['choices'][0]['message']['content'] ?? '');
            
            return !empty($summary) ? $summary : null;
            
        } catch (\Throwable $e) {
            Log::warning('conversation.summary_failed', [
                'error' => $e->getMessage(),
                'messages_count' => count($messages),
            ]);
            
            // Fallback: usa solo gli ultimi 2 messaggi
            $lastTwo = array_slice($messages, -2);
            $fallback = '';
            foreach ($lastTwo as $msg) {
                $speaker = $msg['role'] === 'user' ? 'Utente' : 'Assistente';
                $fallback .= "{$speaker}: {$msg['content']}\n";
            }
            return trim($fallback);
        }
    }
    
    /**
     * Genera query arricchita con contesto conversazionale
     */
    private function generateContextualQuery(string $currentQuery, string $context): string
    {
        // Strategia semplice: prepend del contesto
        $maxContextLength = config('rag.conversation.max_context_in_query', 200);
        $truncatedContext = $this->truncateMessage($context, $maxContextLength);
        
        return "Contesto conversazione precedente: {$truncatedContext}\n\nDomanda attuale: {$currentQuery}";
    }
    
    /**
     * Tronca messaggio mantenendo parole intere
     */
    private function truncateMessage(string $message, int $maxLength): string
    {
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }
        
        $truncated = mb_substr($message, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');
        
        if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        
        return $truncated . '...';
    }
    
    /**
     * Verifica se il conversation context è abilitato
     */
    public function isEnabled(): bool
    {
        return config('rag.conversation.enabled', false) === true;
    }
}
