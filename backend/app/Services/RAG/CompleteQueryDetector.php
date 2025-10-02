<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Log;

/**
 * 🎯 CompleteQueryDetector
 * 
 * Rileva quando una query richiede completezza assoluta invece di rilevanza semantica.
 * Per queste query, bypassa il retrieval normale e recupera tutti i chunk rilevanti.
 */
class CompleteQueryDetector
{
    /**
     * Pattern che indicano richieste di completezza
     */
    private const COMPLETE_INTENT_PATTERNS = [
        // Quantità specifiche - ESTESO
        '/\b(?:tutti|tutte)\s+(?:i|le|gli)?\s*(?:consiglieri|assessori|membri|sindaci)/i',
        '/\belenco\s+(?:completo|totale|integrale)/i',
        '/\b(?:elenco|lista)\s+(?:di\s+)?(?:tutti|tutte)/i',
        '/\b(?:completo|completa|intero|intera|totale)\s+(?:elenco|lista)/i',
        
        // Numeri specifici che indicano completezza
        '/\b(?:23|ventitr[eè]|16|sedici)\s+(?:consiglieri|membri|persone)/i',
        '/\btut[it]e?\s+(?:e\s+)?(?:23|ventitr[eè]|16|sedici)/i',
        
        // Keywords di completezza - ESTESO
        '/\b(?:ogni|ciascun[oa]?|qualsiasi)\s+(?:consigliere|assessore|membro)/i',
        '/\bsenza\s+(?:eccezioni?|esclusioni?)/i',
        '/\b(?:giunta\s+e\s+consiglio|consiglio\s+e\s+giunta)/i',
        '/\borgani\s+politico[-\s]?amministrativ[io]/i',
        
        // Pattern aggiuntivi comuni
        '/\b(?:chi\s+sono\s+tutti|quali\s+sono\s+tutti)\s+(?:i|gli)\s+(?:consiglieri|assessori)/i',
        '/\b(?:nomi\s+di\s+tutti|nomi\s+completi)/i',
        '/\b(?:intera\s+amministrazione|tutta\s+l[\'\']amministrazione)/i',
        '/\b(?:composizione\s+(?:completa|totale))/i',
        '/\b(?:struttura\s+(?:completa|totale))\s+(?:del\s+)?(?:comune|consiglio|giunta)/i',
        
        // Pattern per servizi e altri domini
        '/\b(?:tutti|tutte)\s+(?:i|le|gli)?\s*(?:servizi|uffici|dipartimenti)/i',
        '/\b(?:elenco|lista)\s+(?:servizi|uffici|contatti)/i',
        '/\b(?:orari\s+di\s+tutti|numeri\s+di\s+tutti)/i'
    ];

    /**
     * Topic specifici che richiedono completezza
     */
    private const COMPLETE_TOPICS = [
        'consiglieri' => [
            'keywords' => ['consigliere', 'consiglieri', 'consiglio comunale', 'presidente consiglio'],
            'document_patterns' => ['organi-politico-amministrativo'],
            'chunk_threshold' => 15  // Minimo chunk da recuperare
        ],
        'assessori' => [
            'keywords' => ['assessore', 'assessori', 'giunta', 'vice sindaco'],
            'document_patterns' => ['organi-politico-amministrativo'],
            'chunk_threshold' => 5
        ],
        'organi_politici' => [
            'keywords' => ['organi politico', 'amministrativo', 'politico-amministrativo', 'sindaco'],
            'document_patterns' => ['organi-politico-amministrativo'],
            'chunk_threshold' => 25
        ],
        'servizi' => [
            'keywords' => ['servizi', 'uffici', 'dipartimenti', 'settori'],
            'document_patterns' => ['servizi', 'uffici', 'contatti'],
            'chunk_threshold' => 20
        ],
        'contatti' => [
            'keywords' => ['contatti', 'telefoni', 'numeri', 'indirizzi'],
            'document_patterns' => ['numeri-indirizzi-utili', 'contatti'],
            'chunk_threshold' => 15
        ],
        'orari' => [
            'keywords' => ['orari', 'apertura', 'chiusura', 'ricevimento'],
            'document_patterns' => ['orari', 'servizi'],
            'chunk_threshold' => 10
        ]
    ];

    /**
     * Rileva se la query richiede completezza assoluta
     */
    public function detectCompleteIntent(string $query): array
    {
        $query = trim($query);
        
        Log::debug('🔍 [COMPLETE-DETECTOR] Analyzing query', [
            'query' => $query,
            'length' => strlen($query)
        ]);

        // STEP 1: Controllo pattern di completezza
        foreach (self::COMPLETE_INTENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                Log::info('✅ [COMPLETE-DETECTOR] Complete intent detected by pattern', [
                    'query' => $query,
                    'pattern' => $pattern
                ]);
                
                return [
                    'is_complete_query' => true,
                    'intent_type' => 'pattern_based',
                    'detected_pattern' => $pattern,
                    'topic' => $this->detectTopic($query),
                    'confidence' => 0.9
                ];
            }
        }

        // STEP 2: Controllo topic specifici con keywords
        foreach (self::COMPLETE_TOPICS as $topicName => $config) {
            $keywordMatches = 0;
            foreach ($config['keywords'] as $keyword) {
                if (stripos($query, $keyword) !== false) {
                    $keywordMatches++;
                }
            }
            
            // Se trova multiple keywords del topic = probabile complete query
            if ($keywordMatches >= 2) {
                Log::info('✅ [COMPLETE-DETECTOR] Complete intent detected by topic', [
                    'query' => $query,
                    'topic' => $topicName,
                    'keyword_matches' => $keywordMatches
                ]);
                
                return [
                    'is_complete_query' => true,
                    'intent_type' => 'topic_based',
                    'topic' => $topicName,
                    'chunk_threshold' => $config['chunk_threshold'],
                    'confidence' => min(0.7 + ($keywordMatches * 0.1), 0.95)
                ];
            }
        }

        // STEP 3: Nessun intent completo rilevato
        return [
            'is_complete_query' => false,
            'intent_type' => 'normal',
            'topic' => null,
            'confidence' => 0.0
        ];
    }

    /**
     * Rileva il topic principale della query
     */
    private function detectTopic(string $query): ?string
    {
        foreach (self::COMPLETE_TOPICS as $topicName => $config) {
            foreach ($config['keywords'] as $keyword) {
                if (stripos($query, $keyword) !== false) {
                    return $topicName;
                }
            }
        }
        
        return null;
    }

    /**
     * Genera strategia di retrieval per query complete
     */
    public function getCompleteRetrievalStrategy(array $intentData): array
    {
        $topic = $intentData['topic'] ?? 'generic';
        $chunkThreshold = $intentData['chunk_threshold'] ?? 25;
        
        return [
            'strategy' => 'complete_retrieval',
            'vector_top_k' => 200,
            'bm25_top_k' => 300,
            'final_top_k' => $chunkThreshold + 10, // Margine di sicurezza
            'reranker_driver' => 'none', // NO reranking per completezza
            'document_patterns' => self::COMPLETE_TOPICS[$topic]['document_patterns'] ?? [],
            'enable_document_level_retrieval' => true
        ];
    }

    /**
     * Verifica se un documento corrisponde ai pattern topic
     */
    public function matchesDocumentPattern(string $documentTitle, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (stripos($documentTitle, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}


