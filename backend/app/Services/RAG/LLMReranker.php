<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIChatService;
use Illuminate\Support\Facades\Log;

class LLMReranker implements RerankerInterface
{
    public function __construct(
        private readonly OpenAIChatService $llm
    ) {}
    
    /**
     * Riordina i candidati usando un LLM come giudice di rilevanza
     */
    public function rerank(string $query, array $candidates, int $topN): array
    {
        if (empty($candidates)) {
            return [];
        }
        
        $startTime = microtime(true);
        $config = config('rag.advanced.llm_reranker', []);
        $batchSize = $config['batch_size'] ?? 5;
        
        $scored = [];
        $batches = array_chunk($candidates, $batchSize);
        $totalLLMCalls = count($batches);
        
        Log::info('llm_reranker.start', [
            'candidates' => count($candidates),
            'batches' => $totalLLMCalls,
            'batch_size' => $batchSize,
        ]);
        
        foreach ($batches as $batchIndex => $batch) {
            try {
                $scores = $this->batchScore($query, $batch);
                
                foreach ($batch as $i => $candidate) {
                    $candidate['llm_score'] = $scores[$i] ?? 0;
                    $candidate['original_score'] = $candidate['score']; // Preserva score originale
                    $candidate['score'] = (float) ($scores[$i] ?? 0) / 100.0; // Normalizza 0-1
                    $scored[] = $candidate;
                }
                
            } catch (\Throwable $e) {
                Log::warning('llm_reranker.batch_failed', [
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                ]);
                
                // Fallback: usa score originali per questo batch
                foreach ($batch as $candidate) {
                    $candidate['llm_score'] = 50; // Score neutro
                    $candidate['original_score'] = $candidate['score'];
                    $scored[] = $candidate;
                }
            }
        }
        
        // Ordina per LLM score (decrescente)
        usort($scored, fn($a, $b) => ($b['llm_score'] ?? 0) <=> ($a['llm_score'] ?? 0));
        
        $endTime = microtime(true);
        $processingTime = round(($endTime - $startTime) * 1000, 2);
        
        Log::info('llm_reranker.completed', [
            'input_candidates' => count($candidates),
            'output_candidates' => min($topN, count($scored)),
            'processing_time_ms' => $processingTime,
            'llm_calls' => $totalLLMCalls,
        ]);
        
        return array_slice($scored, 0, $topN);
    }
    
    /**
     * Valuta un batch di candidati usando LLM
     */
    private function batchScore(string $query, array $candidates): array
    {
        $config = config('rag.advanced.llm_reranker', []);
        $model = $config['model'] ?? 'gpt-4o-mini';
        $maxTokens = $config['max_tokens'] ?? 50;
        
        // Costruisci prompt per valutazione batch
        $prompt = $this->buildBatchPrompt($query, $candidates);
        
        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Sei un esperto valutatore di rilevanza. Analizza la rilevanza di ogni testo per la domanda e assegna un punteggio da 0 a 100. Rispondi SOLO con i punteggi separati da virgola.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.1, // Bassa temperatura per consistenza
        ];
        
        $response = $this->llm->chatCompletions($payload);
        $responseText = $response['choices'][0]['message']['content'] ?? '';
        
        // Parsing della risposta
        return $this->parseScores($responseText, count($candidates));
    }
    
    /**
     * Costruisce il prompt per valutazione batch
     */
    private function buildBatchPrompt(string $query, array $candidates): string
    {
        $prompt = "Valuta la rilevanza di questi testi per la domanda (punteggio 0-100):\n\n";
        $prompt .= "DOMANDA: {$query}\n\n";
        
        foreach ($candidates as $i => $candidate) {
            $text = $this->truncateText($candidate['text'], 300); // Limita lunghezza
            $prompt .= "TESTO " . ($i + 1) . ": {$text}\n\n";
        }
        
        $prompt .= "Criteri di valutazione:\n";
        $prompt .= "- 90-100: Risposta diretta e completa alla domanda\n";
        $prompt .= "- 70-89: Informazioni molto rilevanti ma incomplete\n";
        $prompt .= "- 50-69: Informazioni parzialmente rilevanti\n";
        $prompt .= "- 30-49: Informazioni marginalmente rilevanti\n";
        $prompt .= "- 0-29: Informazioni non rilevanti\n\n";
        
        $prompt .= "Rispondi SOLO con i punteggi separati da virgola (es: 85,72,91,45,63): ";
        
        return $prompt;
    }
    
    /**
     * Limita la lunghezza del testo per il prompt
     */
    private function truncateText(string $text, int $maxChars): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        
        // Tronca a parola intera più vicina
        $truncated = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($truncated, ' ');
        
        if ($lastSpace !== false && $lastSpace > $maxChars * 0.8) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        
        return $truncated . '...';
    }
    
    /**
     * Parsing della risposta LLM in array di punteggi
     */
    private function parseScores(string $response, int $expectedCount): array
    {
        // Rimuovi spazi e caratteri extra
        $response = trim($response);
        
        // Estrai numeri dalla risposta
        preg_match_all('/\d+/', $response, $matches);
        $numbers = array_map('intval', $matches[0]);
        
        // Valida e normalizza
        $scores = [];
        for ($i = 0; $i < $expectedCount; $i++) {
            if (isset($numbers[$i])) {
                // Clamp tra 0-100
                $score = max(0, min(100, $numbers[$i]));
                $scores[] = $score;
            } else {
                // Score di default se mancante
                $scores[] = 50;
                Log::warning('llm_reranker.missing_score', [
                    'position' => $i,
                    'response' => $response,
                ]);
            }
        }
        
        return $scores;
    }
    
    /**
     * Verifica se LLM reranking è abilitato
     */
    public function isEnabled(): bool
    {
        return config('rag.advanced.llm_reranker.enabled', false) === true;
    }
}
