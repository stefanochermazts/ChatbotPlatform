<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Log;

/**
 * Servizio per migliorare la coerenza dei link estratti nei chunk RAG
 */
class LinkConsistencyService
{
    /**
     * Filtra e migliora i link nei chunk prima di passarli all'LLM
     */
    public function filterLinksInContext(array $citations, string $query): array
    {
        Log::info('🔗 [LINK-FILTER] Iniziando filtro link per migliorare coerenza', [
            'citations_count' => count($citations),
            'query' => $query,
        ]);

        $filteredCitations = [];
        $queryKeywords = $this->extractQueryKeywords($query);

        foreach ($citations as $citation) {
            $originalContent = $citation['snippet'] ?? $citation['chunk_text'] ?? '';
            $filteredContent = $this->filterLinksInContent(
                $originalContent,
                $citation['document_source_url'] ?? null,
                $queryKeywords
            );

            // Aggiorna la citazione con contenuto filtrato
            $citation['snippet'] = $filteredContent;
            $citation['filtered'] = true;

            $filteredCitations[] = $citation;
        }

        Log::info('🔗 [LINK-FILTER] Filtro completato', [
            'processed_citations' => count($filteredCitations),
        ]);

        return $filteredCitations;
    }

    /**
     * Filtra i link in un contenuto specifico
     */
    private function filterLinksInContent(string $content, ?string $docSourceUrl, array $queryKeywords): string
    {
        // 1. Rimuovi link di navigazione/paginazione
        $content = $this->removeNavigationLinks($content);

        // 2. Rimuovi link cross-domain non rilevanti
        $content = $this->removeCrossDomainLinks($content, $docSourceUrl);

        // 3. Pulisci markdown malformato
        $content = $this->cleanMalformedMarkdown($content);

        // 4. Filtra link irrilevanti per la query
        $content = $this->filterIrrelevantLinks($content, $queryKeywords);

        // 5. Aggiungi metadata per URL rimanenti
        $content = $this->enhanceRemainingLinks($content, $docSourceUrl);

        return $content;
    }

    /**
     * Estrae keywords rilevanti dalla query
     */
    private function extractQueryKeywords(string $query): array
    {
        $query = strtolower($query);

        // Rimuovi stop words italiane
        $stopWords = [
            'come', 'fare', 'per', 'chi', 'cosa', 'dove', 'quando', 'perché',
            'il', 'la', 'di', 'da', 'in', 'con', 'su', 'si', 'è', 'un', 'una',
        ];

        $words = preg_split('/\s+/', $query);
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && ! in_array($word, $stopWords);
        });

        return array_values($keywords);
    }

    /**
     * Rimuove link di navigazione e paginazione
     */
    private function removeNavigationLinks(string $content): string
    {
        // Pattern per link di navigazione comuni
        $navigationPatterns = [
            // Paginazione numerica
            '/\[(\d+)\]\(https?:\/\/[^)]*pag=\d+[^)]*\)/',
            '/\[(\d+)\]\(https?:\/\/[^)]*page=\d+[^)]*\)/',

            // Navigation words
            '/\[(Pagina\s+)?(successiva?|precedente|next|previous|avanti|indietro)\]\([^)]+\)/i',
            '/\[(Prima|Ultima|Home|Homepage)\]\([^)]+\)/i',

            // Menu e breadcrumb generici
            '/\[(Menu|Navigazione|Breadcrumb)\]\([^)]+\)/i',

            // Link vuoti o generici
            '/\[#\]\([^)]+\)/',
            '/\[\s*\]\([^)]+\)/',
            '/\[(scarica\s+documento?|download|pdf)\]\([^)]+\)/i',

            // Link JavaScript o vuoti
            '/\[[^\]]*\]\(javascript:[^)]*\)/',
            '/\[[^\]]*\]\(#[^)]*\)/',
        ];

        foreach ($navigationPatterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        return $content;
    }

    /**
     * Rimuove link cross-domain non rilevanti
     */
    private function removeCrossDomainLinks(string $content, ?string $docSourceUrl): string
    {
        if (! $docSourceUrl) {
            return $content;
        }

        $docDomain = parse_url($docSourceUrl, PHP_URL_HOST);
        if (! $docDomain) {
            return $content;
        }

        // Lista di domini sempre permessi (servizi governativi/istituzionali)
        $allowedDomains = [
            'anagrafenazionale.interno.it',
            'normattiva.it',
            'gazzettaufficiale.it',
            'agenziaentrate.gov.it',
            'inps.it',
            'pec.it',
        ];

        // Pattern per estrarre tutti i link markdown
        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fullMatch = $match[0];
            $linkText = $match[1];
            $url = $match[2];

            $linkDomain = parse_url($url, PHP_URL_HOST);

            if ($linkDomain && $linkDomain !== $docDomain) {
                // Mantieni solo domini esplicitamente permessi
                $isAllowed = false;
                foreach ($allowedDomains as $allowedDomain) {
                    if (str_contains($linkDomain, $allowedDomain)) {
                        $isAllowed = true;
                        break;
                    }
                }

                if (! $isAllowed) {
                    // Rimuovi il link ma mantieni il testo
                    $content = str_replace($fullMatch, $linkText, $content);

                    Log::debug('🔗 [LINK-FILTER] Rimosso link cross-domain', [
                        'text' => $linkText,
                        'removed_domain' => $linkDomain,
                        'doc_domain' => $docDomain,
                    ]);
                }
            }
        }

        return $content;
    }

    /**
     * Pulisce markdown malformato
     */
    private function cleanMalformedMarkdown(string $content): string
    {
        // 1. Fix doppi link markdown
        $content = preg_replace('/\[\[([^\]]+)\]\([^)]+\)\]\([^)]+\)/', '[$1]', $content);

        // 2. Fix URL con caratteri escaped
        $content = str_replace('\/', '/', $content);

        // 3. Rimuovi attributi HTML dai link markdown
        $content = preg_replace('/\[([^\]]+)\]\(([^")]+)"[^)]*"\)/', '[$1]($2)', $content);

        // 4. Fix link con doppia parentesi
        $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\]\([^)]+\)/', '[$1]($2)', $content);

        // 5. Rimuovi URL malformati (con spazi o caratteri strani)
        $content = preg_replace('/\[([^\]]+)\]\([^)]*\s[^)]*\)/', '$1', $content);

        return $content;
    }

    /**
     * Filtra link irrilevanti per la query specifica
     */
    private function filterIrrelevantLinks(string $content, array $queryKeywords): string
    {
        if (empty($queryKeywords)) {
            return $content;
        }

        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fullMatch = $match[0];
            $linkText = strtolower($match[1]);
            $url = $match[2];

            // Calcola relevance score
            $relevanceScore = 0;
            foreach ($queryKeywords as $keyword) {
                if (str_contains($linkText, $keyword)) {
                    $relevanceScore += 2; // Peso maggiore per keyword nel testo
                }
                if (str_contains(strtolower($url), $keyword)) {
                    $relevanceScore += 1; // Peso minore per keyword nell'URL
                }
            }

            // Se il link ha relevance score molto basso, rimuovilo
            if ($relevanceScore === 0 && $this->isGenericLink($linkText)) {
                $content = str_replace($fullMatch, $match[1], $content);

                Log::debug('🔗 [LINK-FILTER] Rimosso link irrilevante', [
                    'text' => $match[1],
                    'url' => $url,
                    'query_keywords' => $queryKeywords,
                ]);
            }
        }

        return $content;
    }

    /**
     * Verifica se un link è generico/non informativo
     */
    private function isGenericLink(string $linkText): bool
    {
        $genericTerms = [
            'clicca qui', 'leggi tutto', 'maggiori informazioni', 'dettagli',
            'continua', 'scopri', 'vai', 'apri', 'vedi', 'consulta',
            'modulo', 'form', 'documenti', 'allegati', 'download',
        ];

        $linkTextLower = strtolower($linkText);

        foreach ($genericTerms as $term) {
            if (str_contains($linkTextLower, $term)) {
                return true;
            }
        }

        // Link molto corti sono spesso generici
        return strlen(trim($linkText)) < 5;
    }

    /**
     * Migliora i link rimanenti con metadata utili
     */
    private function enhanceRemainingLinks(string $content, ?string $docSourceUrl): string
    {
        if (! $docSourceUrl) {
            return $content;
        }

        // Aggiungi contesto al documento fonte alla fine del contenuto
        $baseUrl = parse_url($docSourceUrl, PHP_URL_SCHEME).'://'.parse_url($docSourceUrl, PHP_URL_HOST);

        // Solo se ci sono ancora link nel contenuto
        if (preg_match('/\[[^\]]+\]\([^)]+\)/', $content)) {
            $content .= "\n\n💡 Fonte: ".$docSourceUrl;
        }

        return $content;
    }

    /**
     * Analizza la qualità dei link in un set di citazioni
     */
    public function analyzeLinkQuality(array $citations): array
    {
        $stats = [
            'total_citations' => count($citations),
            'total_links' => 0,
            'cross_domain_links' => 0,
            'navigation_links' => 0,
            'malformed_links' => 0,
            'relevant_links' => 0,
        ];

        foreach ($citations as $citation) {
            $content = $citation['snippet'] ?? $citation['chunk_text'] ?? '';
            $docSourceUrl = $citation['document_source_url'] ?? null;

            preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER);
            $stats['total_links'] += count($matches);

            $docDomain = $docSourceUrl ? parse_url($docSourceUrl, PHP_URL_HOST) : null;

            foreach ($matches as $match) {
                $linkText = $match[1];
                $url = $match[2];
                $linkDomain = parse_url($url, PHP_URL_HOST);

                // Cross-domain check
                if ($docDomain && $linkDomain && $linkDomain !== $docDomain) {
                    $stats['cross_domain_links']++;
                }

                // Navigation check
                if ($this->isNavigationLink($linkText, $url)) {
                    $stats['navigation_links']++;
                }

                // Malformed check
                if (! filter_var($url, FILTER_VALIDATE_URL) || str_contains($url, ' ')) {
                    $stats['malformed_links']++;
                }

                // Relevant check (basic heuristic)
                if (! $this->isGenericLink($linkText) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $stats['relevant_links']++;
                }
            }
        }

        $stats['quality_score'] = $stats['total_links'] > 0
            ? round(($stats['relevant_links'] / $stats['total_links']) * 100, 1)
            : 0;

        return $stats;
    }

    private function isNavigationLink(string $linkText, string $url): bool
    {
        $navTerms = ['pagina', 'next', 'previous', 'precedente', 'successiva', 'home', 'menu'];
        $linkTextLower = strtolower($linkText);

        foreach ($navTerms as $term) {
            if (str_contains($linkTextLower, $term)) {
                return true;
            }
        }

        return preg_match('/^\d+$/', trim($linkText)) || str_contains($url, 'pag=') || str_contains($url, 'page=');
    }
}
