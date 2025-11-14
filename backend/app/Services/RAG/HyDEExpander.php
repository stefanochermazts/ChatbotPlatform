<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIChatService;
use App\Services\LLM\OpenAIEmbeddingsService;
use Illuminate\Support\Facades\Log;

class HyDEExpander
{
    public function __construct(
        private readonly OpenAIChatService $llm,
        private readonly OpenAIEmbeddingsService $embeddings,
        private readonly TenantRagConfigService $tenantConfig = new TenantRagConfigService
    ) {}

    /**
     * Espande la query usando HyDE (Hypothetical Document Embeddings)
     * Genera una risposta ipotetica e usa il suo embedding per la ricerca
     */
    public function expandQuery(string $query, int $tenantId, bool $debug = false): array
    {
        $startTime = microtime(true);

        try {
            // Genera documento ipotetico
            $hypotheticalDoc = $this->generateHypotheticalAnswer($tenantId, $query);

            // Crea embeddings per entrambi
            $originalEmb = $this->embeddings->embedTexts([$query])[0] ?? null;
            $hypotheticalEmb = $this->embeddings->embedTexts([$hypotheticalDoc])[0] ?? null;

            if (! $originalEmb || ! $hypotheticalEmb) {
                throw new \RuntimeException('Failed to generate embeddings');
            }

            // Combina embeddings (weighted average)
            $weights = $this->getWeights();
            $combinedEmb = $this->combineEmbeddings(
                $originalEmb,
                $hypotheticalEmb,
                $weights['original'],
                $weights['hypothetical']
            );

            $endTime = microtime(true);
            $processingTime = round(($endTime - $startTime) * 1000, 2);

            $result = [
                'original_query' => $query,
                'hypothetical_document' => $hypotheticalDoc,
                'original_embedding' => $originalEmb,
                'hypothetical_embedding' => $hypotheticalEmb,
                'combined_embedding' => $combinedEmb,
                'weights' => $weights,
                'processing_time_ms' => $processingTime,
                'success' => true,
            ];

            if ($debug) {
                Log::info('hyde.expansion_success', [
                    'tenant_id' => $tenantId,
                    'query_length' => mb_strlen($query),
                    'hypothetical_length' => mb_strlen($hypotheticalDoc),
                    'processing_time_ms' => $processingTime,
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            $endTime = microtime(true);
            $processingTime = round(($endTime - $startTime) * 1000, 2);

            Log::warning('hyde.expansion_failed', [
                'tenant_id' => $tenantId,
                'query' => $query,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
            ]);

            // Fallback: ritorna query originale
            return [
                'original_query' => $query,
                'hypothetical_document' => null,
                'original_embedding' => null,
                'hypothetical_embedding' => null,
                'combined_embedding' => null,
                'weights' => null,
                'processing_time_ms' => $processingTime,
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Genera una risposta ipotetica dettagliata alla query
     */
    private function generateHypotheticalAnswer(int $tenantId, string $query): string
    {
        $advanced = $this->tenantConfig->getAdvancedConfig($tenantId);
        $config = (array) ($advanced['hyde'] ?? []);
        $model = $config['model'] ?? 'gpt-4o-mini';
        $maxTokens = $config['max_tokens'] ?? 200;
        $temperature = $config['temperature'] ?? 0.3;

        $prompt = $this->buildHypotheticalPrompt($query);

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Sei un esperto di amministrazione pubblica italiana. Conosci perfettamente l\'organizzazione dei comuni, gli organi istituzionali, i ruoli amministrativi e i servizi pubblici. Scrivi risposte complete e precise come se fossero documenti ufficiali.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        $response = $this->llm->chatCompletions($payload);
        $hypotheticalDoc = $response['choices'][0]['message']['content'] ?? '';

        // Pulisci e valida la risposta
        $hypotheticalDoc = trim($hypotheticalDoc);

        if (empty($hypotheticalDoc)) {
            throw new \RuntimeException('LLM returned empty hypothetical document');
        }

        return $hypotheticalDoc;
    }

    /**
     * Costruisce il prompt per generare il documento ipotetico
     */
    private function buildHypotheticalPrompt(string $query): string
    {
        // 🔧 Prompt ottimizzato per query formali e contesto comunale
        return "Scrivi una risposta dettagliata e precisa come se fosse un documento ufficiale di un comune italiano che risponde a: {$query}\n\n".
               "La risposta deve:\n".
               "- Includere nomi completi, ruoli e informazioni specifiche\n".
               "- Essere strutturata come informazione pubblica comunale\n".
               "- Contenere tutti i dettagli che un cittadino potrebbe cercare\n".
               "- Includere elenchi completi quando si tratta di organi istituzionali\n".
               "- Per domande su 'chi sono', 'quali sono', 'elenco': fornire sempre liste complete\n".
               "- Usare terminologia amministrativa italiana (sindaco, consigliere, assessore, presidente del consiglio)\n".
               "- Essere scritta in italiano formale ma accessibile\n\n".
               'Documento di risposta:';
    }

    /**
     * Combina due embeddings con pesi specificati
     */
    private function combineEmbeddings(
        array $embedding1,
        array $embedding2,
        float $weight1,
        float $weight2
    ): array {
        $combined = [];
        $dimension = min(count($embedding1), count($embedding2));

        for ($i = 0; $i < $dimension; $i++) {
            $combined[$i] = ($embedding1[$i] * $weight1) + ($embedding2[$i] * $weight2);
        }

        return $combined;
    }

    /**
     * Ottiene i pesi per combinare gli embeddings
     */
    private function getWeights(): array
    {
        $config = config('rag.advanced.hyde', []);

        return [
            'original' => $config['weight_original'] ?? 0.6,
            'hypothetical' => $config['weight_hypothetical'] ?? 0.4,
        ];
    }

    /**
     * Verifica se HyDE è abilitato per questo tenant
     */
    public function isEnabled(): bool
    {
        // Il check di abilitazione viene gestito da KbSearchService passando il tenantId
        return true;
    }
}
