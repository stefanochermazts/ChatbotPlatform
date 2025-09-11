<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Log;

class QueryTypeClassifier
{
    // Query patterns per classificazione
    private const PATTERNS = [
        'factual' => [
            // Domande che richiedono fatti specifici
            'keywords' => ['telefono', 'numero', 'email', 'indirizzo', 'orario', 'costo', 'prezzo', 'data', 'quando', 'dove', 'chi', 'quanto'],
            'patterns' => [
                '/(?:qual[ei]?\s+(?:è|sono)|che cosa|cosa)\s+.{1,30}\?/i',
                '/(?:numero|telefono|email|indirizzo|orari?)\s+(?:di|del|della|dei|degli)/i',
                '/(?:quando|dove|chi|quanto|come|perché)\s+/i',
                '/(?:^|\s)(?:tel\.?|tel:|email:|fax:)/i'
            ],
            'weight' => 2.0
        ],
        'navigational' => [
            // Query per trovare documenti/sezioni specifiche
            'keywords' => ['modulo', 'form', 'documento', 'pagina', 'sezione', 'area', 'ufficio', 'servizio'],
            'patterns' => [
                '/(?:dove\s+(?:trovo|posso\s+trovare)|come\s+accedere|vai\s+a)/i',
                '/(?:modulo|form|documento|pagina)\s+(?:per|di|del)/i',
                '/(?:area|sezione|ufficio)\s+/i'
            ],
            'weight' => 1.5
        ],
        'procedural' => [
            // Come fare qualcosa, processi step-by-step
            'keywords' => ['come', 'procedura', 'processo', 'passaggi', 'step', 'fare', 'ottenere', 'richiedere'],
            'patterns' => [
                '/^come\s+/i',
                '/(?:come\s+(?:fare|ottenere|richiedere)|procedura\s+per|processo\s+di)/i',
                '/(?:passaggi|step|fasi)\s+/i',
                '/(?:cosa\s+devo\s+fare|quali\s+sono\s+i\s+passaggi)/i'
            ],
            'weight' => 1.8
        ],
        'informational' => [
            // Query informative generali
            'keywords' => ['cos\'è', 'cosa è', 'spiegami', 'dimmi', 'informazioni', 'dettagli', 'descrizione'],
            'patterns' => [
                '/(?:cos\'è|cosa\s+è|che\s+cos\'è)\s+/i',
                '/(?:spiegami|dimmi|parlami)\s+(?:di|del|della)/i',
                '/(?:informazioni|dettagli|descrizione)\s+(?:su|di|del)/i',
                '/(?:che\s+tipo\s+di|quali\s+sono\s+le\s+caratteristiche)/i'
            ],
            'weight' => 1.0
        ],
        'comparative' => [
            // Query di confronto
            'keywords' => ['differenza', 'confronto', 'meglio', 'peggio', 'versus', 'vs', 'rispetto'],
            'patterns' => [
                '/(?:differenza\s+tra|confronto\s+tra|meglio\s+di)/i',
                '/(?:versus|vs\.?|rispetto\s+a)/i',
                '/(?:quale\s+(?:è\s+)?(?:meglio|peggio))/i'
            ],
            'weight' => 1.7
        ]
    ];
    
    /**
     * Classifica il tipo di query
     */
    public function classify(string $query): array
    {
        $query = trim(strtolower($query));
        $scores = [];
        
        foreach (self::PATTERNS as $type => $config) {
            $score = $this->calculateTypeScore($query, $config);
            if ($score > 0) {
                $scores[$type] = $score;
            }
        }
        
        // Ordina per score decrescente
        arsort($scores);
        
        // Determina tipo primario e confidence
        $primaryType = !empty($scores) ? array_key_first($scores) : 'informational';
        $confidence = !empty($scores) ? array_values($scores)[0] : 0.1;
        
        // Normalizza confidence 0-1
        $confidence = min(1.0, $confidence / 5.0);
        
        $result = [
            'primary_type' => $primaryType,
            'confidence' => $confidence,
            'all_scores' => $scores,
            'features' => $this->extractFeatures($query)
        ];
        
        Log::debug('🔍 [QUERY_CLASSIFIER] Query classified', [
            'query' => substr($query, 0, 100),
            'primary_type' => $primaryType,
            'confidence' => round($confidence, 3),
            'all_scores' => array_map(fn($s) => round($s, 2), $scores)
        ]);
        
        return $result;
    }
    
    /**
     * Calcola score per un tipo specifico
     */
    private function calculateTypeScore(string $query, array $config): float
    {
        $score = 0.0;
        $weight = $config['weight'] ?? 1.0;
        
        // Score basato su keywords
        foreach ($config['keywords'] as $keyword) {
            if (strpos($query, $keyword) !== false) {
                // Bonus per parole complete vs substring
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $query)) {
                    $score += 1.0;
                } else {
                    $score += 0.5;
                }
            }
        }
        
        // Score basato su pattern regex
        foreach ($config['patterns'] as $pattern) {
            if (preg_match($pattern, $query)) {
                $score += 2.0; // Pattern match è più significativo
            }
        }
        
        return $score * $weight;
    }
    
    /**
     * Estrae caratteristiche della query
     */
    private function extractFeatures(string $query): array
    {
        return [
            'length' => strlen($query),
            'word_count' => str_word_count($query),
            'has_question_mark' => strpos($query, '?') !== false,
            'has_numbers' => preg_match('/\d/', $query),
            'has_email_phone' => preg_match('/(?:email|telefono|tel\.?|@|\.it)/', $query),
            'starts_with_wh' => preg_match('/^(?:che|chi|cosa|come|quando|dove|perché|quanto)/i', $query),
            'is_imperative' => preg_match('/^(?:dimmi|spiegami|mostrami|trova)/i', $query),
        ];
    }
    
    /**
     * Raccomanda il miglior reranker per il tipo di query
     */
    public function recommendReranker(array $classification): string
    {
        $type = $classification['primary_type'];
        $confidence = $classification['confidence'];
        $features = $classification['features'];
        
        // Logica di selezione reranker
        $recommendation = match($type) {
            'factual' => 'llm', // LLM eccelle nel trovare fatti specifici
            'navigational' => 'embedding', // Embedding per similarità semantica
            'procedural' => 'llm', // LLM per comprendere processi complessi
            'informational' => 'embedding', // Embedding per overview generali
            'comparative' => 'llm', // LLM per analisi comparative
            default => 'embedding'
        };
        
        // Override basato su caratteristiche specifiche
        if ($features['has_email_phone']) {
            $recommendation = 'llm'; // Meglio per dati strutturati
        }
        
        if ($features['word_count'] <= 3 && $confidence < 0.5) {
            $recommendation = 'embedding'; // Query troppo corte/ambigue
        }
        
        Log::info('🎯 [RERANKER_RECOMMENDATION] Reranker selected', [
            'query_type' => $type,
            'recommended_reranker' => $recommendation,
            'confidence' => round($confidence, 3),
            'reasoning' => $this->explainRecommendation($type, $features, $recommendation)
        ]);
        
        return $recommendation;
    }
    
    /**
     * Spiega la logica di raccomandazione
     */
    private function explainRecommendation(string $type, array $features, string $reranker): string
    {
        $reasons = [];
        
        switch ($type) {
            case 'factual':
                $reasons[] = 'factual query benefits from LLM precision';
                break;
            case 'navigational':
                $reasons[] = 'navigational query uses semantic similarity';
                break;
            case 'procedural':
                $reasons[] = 'procedural query needs LLM understanding';
                break;
            case 'informational':
                $reasons[] = 'informational query uses broad semantic matching';
                break;
        }
        
        if ($features['has_email_phone']) {
            $reasons[] = 'contains contact info, LLM better for structured data';
        }
        
        if ($features['word_count'] <= 3) {
            $reasons[] = 'short query, embedding for broad matching';
        }
        
        return implode('; ', $reasons);
    }
}
