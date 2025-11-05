<?php

namespace App\Services\RAG;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralised synonym expansion service for consistent query enhancement across all RAG retrieval paths.
 *
 * Expands queries with tenant-specific or global synonyms to improve:
 * - Vector search (semantic similarity)
 * - BM25 full-text search
 * - Intent-based lookup
 */
class SynonymExpansionService
{
    /**
     * Expand query with tenant-specific synonyms
     *
     * @param  string  $query  Original query text
     * @param  int|null  $tenantId  Tenant ID for custom synonyms (null = use global synonyms)
     * @return string Expanded query with synonyms appended
     */
    public function expand(string $query, ?int $tenantId = null): string
    {
        if ($query === '') {
            return $query;
        }

        // Cache key per tenant per evitare lookup ripetuti
        $cacheKey = "synonym_expansion:t{$tenantId}:".md5($query);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($query, $tenantId) {
            $synonyms = $this->getTenantSynonyms($tenantId);

            if (empty($synonyms)) {
                return $query; // Nessun sinonimo configurato
            }

            // Normalizza la query per il matching case-insensitive
            $queryLower = mb_strtolower($query);
            $expanded = $query;
            $addedSynonyms = [];

            // Ordina i sinonimi per lunghezza decrescente per match più specifici prima
            $sortedSynonyms = $synonyms;
            uksort($sortedSynonyms, fn ($a, $b) => strlen($b) - strlen($a));

            foreach ($sortedSynonyms as $term => $synonymList) {
                $termLower = mb_strtolower($term);

                // Match case-insensitive con word boundary per evitare match parziali
                // Es: "comune" non deve matchare in "comunemente"
                if (preg_match('/\b'.preg_quote($termLower, '/').'\b/u', $queryLower)) {
                    // Aggiungi i sinonimi evitando duplicati
                    $synonymWords = preg_split('/[\s,]+/', $synonymList, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($synonymWords as $synonym) {
                        $synonym = trim($synonym);
                        $synonymLower = mb_strtolower($synonym);

                        // Evita duplicati: non aggiungere se già presente nella query o nei sinonimi aggiunti
                        // FIX: Use word boundary regex instead of str_contains to avoid false positives
                        // (e.g., "tel" should not match inside "telefono")
                        $alreadyInQuery = preg_match('/\b'.preg_quote($synonymLower, '/').'\b/u', $queryLower);

                        if ($synonym !== '' &&
                            ! $alreadyInQuery &&
                            ! in_array($synonymLower, $addedSynonyms, true)) {
                            $addedSynonyms[] = $synonymLower;
                        }
                    }
                }
            }

            // Aggiungi i sinonimi alla query
            if (! empty($addedSynonyms)) {
                $expanded .= ' '.implode(' ', $addedSynonyms);

                Log::debug('[SYNONYM-EXPANSION] Query expanded', [
                    'original' => $query,
                    'expanded' => $expanded,
                    'tenant_id' => $tenantId,
                    'synonyms_added' => count($addedSynonyms),
                    'synonyms' => $addedSynonyms,
                ]);
            }

            return $expanded;
        });
    }

    /**
     * Expand a specific name/term (e.g., extracted entity like "anagrafe") with synonyms
     *
     * @param  string  $name  Entity name to expand
     * @param  int|null  $tenantId  Tenant ID
     * @return string Expanded name with synonyms
     */
    public function expandName(string $name, ?int $tenantId = null): string
    {
        $synonyms = $this->getTenantSynonyms($tenantId);

        // Colleziona tutti i termini senza duplicazioni
        $allTerms = [$name];

        // Ordina i sinonimi per lunghezza decrescente per match più specifici prima
        $sortedSynonyms = $synonyms;
        uksort($sortedSynonyms, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sortedSynonyms as $term => $synonymList) {
            if (str_contains(strtolower($name), strtolower($term))) {
                // Aggiungi i sinonimi alla collezione
                $synonymWords = explode(' ', $synonymList);
                foreach ($synonymWords as $word) {
                    $word = trim($word);
                    if ($word !== '' && ! in_array(strtolower($word), array_map('strtolower', $allTerms))) {
                        $allTerms[] = $word;
                    }
                }
                // Prendi solo il primo match per evitare sovrapposizioni
                break;
            }
        }

        return implode(' ', $allTerms);
    }

    /**
     * Get tenant-specific synonyms with fallback to global synonyms
     *
     * @param  int|null  $tenantId  Tenant ID
     * @return array<string, string> Synonym map (term => synonyms string)
     */
    private function getTenantSynonyms(?int $tenantId): array
    {
        if ($tenantId === null) {
            return $this->getGlobalSynonyms();
        }

        // Cache tenant synonyms
        return Cache::remember("tenant_synonyms:{$tenantId}", now()->addMinutes(10), function () use ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                return $this->getGlobalSynonyms();
            }

            // Se il tenant ha sinonimi personalizzati, usali; altrimenti fallback
            if (! empty($tenant->custom_synonyms)) {
                return (array) $tenant->custom_synonyms;
            }

            return $this->getGlobalSynonyms();
        });
    }

    /**
     * Global synonym map (fallback when tenant has no custom synonyms)
     *
     * @return array<string, string> Synonym map
     */
    private function getGlobalSynonyms(): array
    {
        return [
            // Sinonimi per forze dell'ordine e servizi pubblici
            'vigili urbani' => 'polizia locale municipale vigili',
            'polizia locale' => 'vigili urbani municipale',
            'carabinieri' => 'cc arma',
            'polizia' => 'questura commissariato',

            // Sinonimi per uffici pubblici
            'comune' => 'municipio municipalità ente locale',
            'municipio' => 'comune municipalità',
            'anagrafe' => 'ufficio anagrafico stato civile',
            'tributi' => 'tasse imposte fiscale',

            // Sinonimi per contatti
            'telefono' => 'tel cellulare numero contatto',
            'cellulare' => 'telefono mobile cell',
            'email' => 'mail posta elettronica e-mail',
            'pec' => 'posta certificata email certificata',

            // Sinonimi per orari e appuntamenti
            'orario' => 'orari apertura disponibilità',
            'orari' => 'orario apertura disponibilità',
            'appuntamento' => 'prenotazione appuntamenti',

            // Sinonimi per indirizzi
            'indirizzo' => 'via piazza sede ubicazione',
            'sede' => 'indirizzo ubicazione dove si trova',
        ];
    }

    /**
     * Invalidate synonym cache for a specific tenant
     *
     * @param  int  $tenantId  Tenant ID
     */
    public function invalidateCache(int $tenantId): void
    {
        Cache::forget("tenant_synonyms:{$tenantId}");
        // Invalidate also expansion cache (wildcard flush)
        Cache::flush(); // Note: In production, use tag-based cache for more granular control
    }
}

