<?php

namespace App\Services\Scraper;

use Illuminate\Support\Facades\Log;

/**
 * 🧠 Enhanced Content Quality Analyzer
 * 
 * Analisi avanzata di qualità e tipo contenuto per ottimizzare strategie di estrazione
 * e ridurre noise nel RAG.
 */
class ContentQualityAnalyzer
{
    private const MINIMUM_QUALITY_THRESHOLD = 0.3;
    private const HIGH_QUALITY_THRESHOLD = 0.7;
    
    /**
     * 🔍 Analisi completa del contenuto con scoring qualità
     */
    public function analyzeContent(string $html, string $url): array
    {
        $startTime = microtime(true);
        
        $analysis = [
            // Tipo contenuto (enhanced)
            'content_type' => $this->detectContentType($html),
            'content_category' => $this->categorizeContent($html),
            
            // Metriche strutturali
            'has_complex_tables' => $this->hasComplexTables($html),
            'has_structured_data' => $this->hasStructuredData($html),
            'has_forms' => $this->hasForms($html),
            'has_navigation' => $this->hasNavigation($html),
            'has_media' => $this->hasMediaContent($html),
            
            // Metriche qualità
            'text_ratio' => $this->calculateTextRatio($html),
            'information_density' => $this->calculateInformationDensity($html),
            'semantic_richness' => $this->calculateSemanticRichness($html),
            'language_quality' => $this->assessLanguageQuality($html),
            
            // Freshness e relevance
            'content_freshness' => $this->detectContentFreshness($html),
            'business_relevance' => $this->assessBusinessRelevance($html),
            
            // Score finale
            'quality_score' => 0.0,
            'extraction_strategy' => 'unknown',
            'processing_priority' => 'normal',
            
            // Metadata
            'analysis_time_ms' => 0,
            'detected_language' => $this->detectLanguage($html),
            'warnings' => []
        ];
        
        // Calcola quality score complessivo
        $analysis['quality_score'] = $this->calculateOverallQualityScore($analysis);
        
        // Determina strategia di estrazione ottimale
        // Strategia verrà determinata dal WebScraperService che ha accesso all'URL
        $analysis['extraction_strategy'] = 'tbd';
        
        // Determina priorità di processing
        $analysis['processing_priority'] = $this->determineProcessingPriority($analysis);
        
        $analysis['analysis_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
        
        Log::debug("🧠 Enhanced Content Analysis", [
            'url' => $url,
            'content_type' => $analysis['content_type'],
            'quality_score' => $analysis['quality_score'],
            'extraction_strategy' => $analysis['extraction_strategy'],
            'processing_time_ms' => $analysis['analysis_time_ms']
        ]);
        
        return $analysis;
    }
    
    /**
     * 🎯 Determina il tipo di contenuto principale
     */
    private function detectContentType(string $html): string
    {
        // Contenuto multimediale
        if ($this->hasSignificantMedia($html)) {
            return 'media_rich';
        }
        
        // Tabelle dati complesse
        if ($this->hasComplexTables($html)) {
            return 'data_table';
        }
        
        // Form interattivi
        if ($this->hasInteractiveForms($html)) {
            return 'interactive_form';
        }
        
        // Navigazione/directory
        if ($this->isNavigationPage($html)) {
            return 'navigation_directory';
        }
        
        // Articolo/contenuto testuale
        if ($this->isArticleContent($html)) {
            return 'article_content';
        }
        
        // Informazioni strutturate (contatti, orari, etc.)
        if ($this->hasStructuredData($html)) {
            return 'structured_info';
        }
        
        // Pagina generica
        return 'generic_page';
    }
    
    /**
     * 📂 Categorizza il contenuto per dominio business
     */
    private function categorizeContent(string $html): string
    {
        $text = strtolower(strip_tags($html));
        
        // Pattern per categoria business
        $categories = [
            'contact_info' => [
                'patterns' => ['/contatti?/', '/telefono/', '/email/', '/indirizzo/', '/sede/'],
                'weight' => 1.0
            ],
            'hours_services' => [
                'patterns' => ['/orari?/', '/apertura/', '/servizi/', '/uffici/'],
                'weight' => 0.9
            ],
            'news_events' => [
                'patterns' => ['/news/', '/notizie/', '/eventi/', '/comunicati/'],
                'weight' => 0.7
            ],
            'procedures_docs' => [
                'patterns' => ['/procedur/', '/modulistic/', '/document/', '/normativ/'],
                'weight' => 0.8
            ],
            'product_catalog' => [
                'patterns' => ['/prodott/', '/catalogo/', '/servizi/', '/offert/'],
                'weight' => 0.7
            ]
        ];
        
        $bestCategory = 'general';
        $bestScore = 0;
        
        foreach ($categories as $category => $config) {
            $score = 0;
            foreach ($config['patterns'] as $pattern) {
                if (preg_match($pattern, $text)) {
                    $score += $config['weight'];
                }
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCategory = $category;
            }
        }
        
        return $bestCategory;
    }
    
    /**
     * 📊 Calcola densità informativa del contenuto
     */
    private function calculateInformationDensity(string $html): float
    {
        $textContent = strip_tags($html);
        $words = str_word_count($textContent);
        $uniqueWords = count(array_unique(str_word_count($textContent, 1)));
        $sentences = preg_split('/[.!?]+/', $textContent);
        
        if ($words === 0) return 0.0;
        
        // Fattori di densità
        $vocabularyRichness = $words > 0 ? $uniqueWords / $words : 0;
        $averageWordsPerSentence = count($sentences) > 0 ? $words / count($sentences) : 0;
        
        // Penalizza contenuto troppo ripetitivo o troppo frammentato
        $density = min(1.0, $vocabularyRichness * 2 + min($averageWordsPerSentence / 20, 0.5));
        
        return round($density, 3);
    }
    
    /**
     * 🎭 Valuta ricchezza semantica (entità, concetti)
     */
    private function calculateSemanticRichness(string $html): float
    {
        $text = strip_tags($html);
        $score = 0.0;
        
        // Pattern per entità rilevanti
        $entityPatterns = [
            'dates' => '/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/',
            'times' => '/\b\d{1,2}:\d{2}\b/',
            'phones' => '/\b(?:\+39\s?)?(?:\d{2,4}[\s\-]?){2,4}\d{2,4}\b/',
            'emails' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'addresses' => '/\b(?:via|piazza|corso|viale|strada)\s+[^,\n]{5,50}/i',
            'monetary' => '/\b\d+(?:[.,]\d{2})?\s?(?:€|euro|eur)\b/i',
            'percentages' => '/\b\d+(?:[.,]\d+)?%\b/',
            'urls' => '/\bhttps?:\/\/[^\s<]+/i'
        ];
        
        foreach ($entityPatterns as $type => $pattern) {
            $matches = preg_match_all($pattern, $text);
            $score += min($matches * 0.1, 0.3); // Max 0.3 per tipo
        }
        
        return min(1.0, $score);
    }
    
    /**
     * 🗣️ Valuta qualità linguistica del contenuto
     */
    private function assessLanguageQuality(string $html): float
    {
        $text = strip_tags($html);
        $words = str_word_count($text, 1);
        
        if (count($words) < 10) return 0.1;
        
        $score = 0.5; // Base score
        
        // Penalizza testo tutto maiuscolo o minuscolo
        $uppercaseRatio = $this->calculateCaseRatio($text, true);
        $lowercaseRatio = $this->calculateCaseRatio($text, false);
        
        if ($uppercaseRatio > 0.8 || $lowercaseRatio > 0.9) {
            $score -= 0.3;
        }
        
        // Bonus per punteggiatura appropriata
        $punctuationDensity = $this->calculatePunctuationDensity($text);
        if ($punctuationDensity > 0.02 && $punctuationDensity < 0.15) {
            $score += 0.2;
        }
        
        // Penalizza caratteri non standard eccessivi
        $specialCharRatio = $this->calculateSpecialCharRatio($text);
        if ($specialCharRatio > 0.1) {
            $score -= 0.2;
        }
        
        return max(0.0, min(1.0, $score));
    }
    
    /**
     * 🕐 Rileva freshness del contenuto
     */
    private function detectContentFreshness(string $html): array
    {
        $freshness = [
            'has_dates' => false,
            'latest_date' => null,
            'is_recent' => false,
            'update_indicators' => []
        ];
        
        // Cerca date nel contenuto
        $datePatterns = [
            '/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/',
            '/\b(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})\b/',
            '/\b(gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre)\s+(\d{4})\b/i'
        ];
        
        foreach ($datePatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                $freshness['has_dates'] = true;
                // Logic per estrarre date più recenti...
                break;
            }
        }
        
        // Indicatori di aggiornamento
        $updateIndicators = [
            'aggiornato', 'modificato', 'updated', 'ultimo aggiornamento',
            'data pubblicazione', 'revisione'
        ];
        
        foreach ($updateIndicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                $freshness['update_indicators'][] = $indicator;
            }
        }
        
        return $freshness;
    }
    
    /**
     * 💼 Valuta rilevanza business del contenuto
     */
    private function assessBusinessRelevance(string $html): float
    {
        $text = strtolower(strip_tags($html));
        $score = 0.0;
        
        // Pattern di alto valore business
        $highValuePatterns = [
            'contact' => ['/contatti?/', '/telefono/', '/email/', '/sede/'],
            'services' => ['/servizi/', '/prodott/', '/offert/', '/prezz/'],
            'procedures' => ['/procedur/', '/modulistic/', '/richiesta/', '/domand/'],
            'hours' => ['/orari/', '/apertura/', '/chiusura/']
        ];
        
        foreach ($highValuePatterns as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    $score += 0.25; // Max 1.0 se tutte le categorie presenti
                    break; // Evita double counting per categoria
                }
            }
        }
        
        return min(1.0, $score);
    }
    
    /**
     * 🧮 Calcola score qualità complessivo
     */
    private function calculateOverallQualityScore(array $analysis): float
    {
        $weights = [
            'text_ratio' => 0.2,
            'information_density' => 0.25,
            'semantic_richness' => 0.2,
            'language_quality' => 0.15,
            'business_relevance' => 0.2
        ];
        
        $score = 0.0;
        foreach ($weights as $metric => $weight) {
            $score += ($analysis[$metric] ?? 0) * $weight;
        }
        
        // Bonus per contenuto strutturato di qualità
        if ($analysis['has_structured_data'] && $analysis['business_relevance'] > 0.5) {
            $score += 0.1;
        }
        
        // Penalità per contenuto di navigazione
        if ($analysis['content_type'] === 'navigation_directory') {
            $score *= 0.7;
        }
        
        return round(min(1.0, max(0.0, $score)), 3);
    }
    
    /**
     * 🎯 Determina strategia di estrazione ottimale
     */
    private function determineExtractionStrategy(array $analysis): string
    {
        // Detect if content seems to have semantic containers (suggests manual DOM would work better)
        if ($this->hasSemanticContentContainers($analysis)) {
            return 'manual_dom_primary';
        }
        
        // Tabelle complesse = metodo manuale
        if ($analysis['has_complex_tables']) {
            return 'manual_dom_primary';
        }
        
        // Contenuto testuale di qualità = Readability
        if ($analysis['content_type'] === 'article_content' && $analysis['quality_score'] > 0.5) {
            return 'readability_primary';
        }
        
        // Dati strutturati = metodo ibrido
        if ($analysis['has_structured_data'] && $analysis['business_relevance'] > 0.6) {
            return 'hybrid_structured';
        }
        
        // Bassa qualità = skip o extraction minimal
        if ($analysis['quality_score'] < self::MINIMUM_QUALITY_THRESHOLD) {
            return 'skip_low_quality';
        }
        
        return 'hybrid_default';
    }

    /**
     * 🎯 Detect if HTML contains semantic content containers
     */
    private function hasSemanticContentContainers(array $analysis): bool
    {
        // Check if HTML was passed in analysis
        if (!isset($analysis['html'])) {
            return false;
        }
        
        $html = $analysis['html'];
        
        // Load semantic patterns from configuration
        $semanticPatterns = config('scraper-patterns.semantic_indicators', [
            'testolungo', 'content-main', 'main-content'
        ]);
        
        foreach ($semanticPatterns as $pattern) {
            if (preg_match('/class="[^"]*' . preg_quote($pattern, '/') . '[^"]*"/i', $html)) {
                Log::debug("🎯 Semantic container detected", [
                    'pattern' => $pattern,
                    'suggests_manual_dom' => true
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * ⚡ Determina priorità di processing
     */
    private function determineProcessingPriority(array $analysis): string
    {
        if ($analysis['quality_score'] >= self::HIGH_QUALITY_THRESHOLD) {
            return 'high';
        }
        
        if ($analysis['business_relevance'] > 0.7) {
            return 'high';
        }
        
        if ($analysis['quality_score'] < self::MINIMUM_QUALITY_THRESHOLD) {
            return 'low';
        }
        
        return 'normal';
    }
    
    // ========== METODI HELPER ==========
    
    private function hasComplexTables(string $html): bool
    {
        $tableCount = substr_count($html, '<table');
        if ($tableCount === 0) return false;
        
        $cellCount = substr_count($html, '<td') + substr_count($html, '<th');
        $responsiveIndicators = substr_count($html, 'hidden-xs') + substr_count($html, 'visible-xs');
        
        return $cellCount > 15 || $responsiveIndicators > 5;
    }
    
    private function hasStructuredData(string $html): bool
    {
        $patterns = [
            '/\b(?:\d{2,4}[\s\-]?){2,4}\d{2,4}\b/', // Phone
            '/\b(?:via|piazza|corso|viale)\s+[^,\n]{5,50}/i', // Address
            '/\b\d{1,2}:\d{2}\s*[-–]\s*\d{1,2}:\d{2}\b/', // Hours
        ];
        
        $matches = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html)) {
                $matches++;
            }
        }
        
        return $matches >= 2;
    }
    
    private function hasForms(string $html): bool
    {
        return substr_count($html, '<form') > 0;
    }
    
    private function hasNavigation(string $html): bool
    {
        $navIndicators = ['<nav', 'menu', 'breadcrumb', 'sidebar'];
        foreach ($navIndicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function hasMediaContent(string $html): bool
    {
        $mediaCount = substr_count($html, '<img') + substr_count($html, '<video') + substr_count($html, '<audio');
        return $mediaCount > 2;
    }
    
    private function calculateTextRatio(string $html): float
    {
        $textLength = strlen(trim(strip_tags($html)));
        $htmlLength = strlen($html);
        
        return $htmlLength > 0 ? $textLength / $htmlLength : 0;
    }
    
    private function detectLanguage(string $html): string
    {
        $text = strip_tags($html);
        
        // Simple heuristic per italiano
        $italianWords = ['di', 'e', 'il', 'la', 'per', 'con', 'del', 'dei', 'dalla', 'nella'];
        $matches = 0;
        
        foreach ($italianWords as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $text)) {
                $matches++;
            }
        }
        
        return $matches >= 3 ? 'it' : 'unknown';
    }
    
    private function hasSignificantMedia(string $html): bool
    {
        $mediaCount = substr_count($html, '<img') + substr_count($html, '<video') + substr_count($html, '<iframe');
        $textLength = strlen(strip_tags($html));
        
        return $mediaCount > 5 && $textLength < 500;
    }
    
    private function hasInteractiveForms(string $html): bool
    {
        $formCount = substr_count($html, '<form');
        $inputCount = substr_count($html, '<input') + substr_count($html, '<select') + substr_count($html, '<textarea');
        
        return $formCount > 0 && $inputCount > 3;
    }
    
    private function isNavigationPage(string $html): bool
    {
        $linkCount = substr_count($html, '<a ');
        $textLength = strlen(strip_tags($html));
        
        return $linkCount > 20 && $textLength < 1000;
    }
    
    private function isArticleContent(string $html): bool
    {
        $textLength = strlen(strip_tags($html));
        $paragraphCount = substr_count($html, '<p');
        
        return $textLength > 500 && $paragraphCount > 3;
    }
    
    private function calculateCaseRatio(string $text, bool $uppercase): float
    {
        $letters = preg_replace('/[^a-zA-Z]/', '', $text);
        if (strlen($letters) === 0) return 0;
        
        $targetCount = $uppercase ? 
            strlen(preg_replace('/[^A-Z]/', '', $letters)) :
            strlen(preg_replace('/[^a-z]/', '', $letters));
            
        return $targetCount / strlen($letters);
    }
    
    private function calculatePunctuationDensity(string $text): float
    {
        $punctuation = preg_replace('/[^.!?,:;]/', '', $text);
        return strlen($text) > 0 ? strlen($punctuation) / strlen($text) : 0;
    }
    
    private function calculateSpecialCharRatio(string $text): float
    {
        $special = preg_replace('/[a-zA-Z0-9\s.!?,:;]/', '', $text);
        return strlen($text) > 0 ? strlen($special) / strlen($text) : 0;
    }
}
