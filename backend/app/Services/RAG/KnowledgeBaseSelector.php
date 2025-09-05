<?php

namespace App\Services\RAG;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class KnowledgeBaseSelector
{
    public function __construct(
        private readonly TextSearchService $text,
        private readonly TenantRagConfigService $tenantConfig = new TenantRagConfigService()
    ) {
    }

    /**
     * Sceglie automaticamente la KB per una query di un tenant.
     * Strategia: usa i top hit BM25 del tenant, raggruppa per KB e sceglie la KB con score aggregato più alto.
     * Fallback: KB di default del tenant (o prima disponibile).
     *
     * @return array{knowledge_base_id:int|null, reason:string, kb_name:string|null}
     */
    public function selectForQuery(int $tenantId, string $query): array
    {
        // Ottieni configurazioni KB selection dalla maschera admin
        $kbConfig = $this->tenantConfig->getKbSelectionConfig($tenantId);
        $mode = $kbConfig['mode'] ?? 'auto';
        $bm25BoostFactor = (float) ($kbConfig['bm25_boost_factor'] ?? 1.0);
        $vectorBoostFactor = (float) ($kbConfig['vector_boost_factor'] ?? 1.0);
        $uploadBoost = (float) ($kbConfig['upload_boost'] ?? 1.0);
        $titleKeywordBoosts = (array) ($kbConfig['title_keyword_boosts'] ?? []);
        $locationBoosts = (array) ($kbConfig['location_boosts'] ?? []);
        
        // Normalizza la query per evitare che punteggiatura e parole di contesto 
        // influenzino negativamente la selezione della KB
        $normalizedQuery = $this->normalizeQueryForKbSelection($query);
        
        // 🔍 LOG: Query normalizzazione per debug
        \Log::info('KB Selection Query Normalization', [
            'tenant_id' => $tenantId,
            'original_query' => $query,
            'normalized_query' => $normalizedQuery,
            'kb_selection_mode' => $mode,
            'bm25_boost_factor' => $bm25BoostFactor,
            'vector_boost_factor' => $vectorBoostFactor,
            'caller' => 'KnowledgeBaseSelector'
        ]);
        // Boost per keyword specifiche SOW (usa query normalizzata)
        $sowKeywords = ['sow', 'statement of work', 'contratto quadro', 'contratti quadro', 'servizi it'];
        $queryLower = mb_strtolower($normalizedQuery);
        foreach ($sowKeywords as $kw) {
            if (str_contains($queryLower, $kw)) {
                // Se la query contiene keyword SOW, cerca solo nelle KB con documenti SOW
                $kbs = DB::table('knowledge_bases as kb')
                    ->join('documents as d', 'd.knowledge_base_id', '=', 'kb.id')
                    ->where('kb.tenant_id', $tenantId)
                    ->where(function($q) {
                        $q->where('d.title', 'like', '%SOW%')
                          ->orWhere('d.title', 'like', '%Statement of Work%')
                          ->orWhere('d.title', 'like', '%Contratto Quadro%');
                    })
                    ->select('kb.id', 'kb.name')
                    ->distinct()
                    ->get();
                
                if ($kbs->isNotEmpty()) {
                    $kb = $kbs->first();
                    return [
                        'knowledge_base_id' => $kb->id,
                        'kb_name' => $kb->name,
                        'reason' => 'sow_keyword_match'
                    ];
                }
            }
        }

        // Ridotto da 200 a 50 per evitare diluizione score (usa query normalizzata)
        $hits = $this->text->searchTopK($tenantId, $normalizedQuery, 50, null);
        if ($hits === []) {
            $kb = $this->getDefaultKb($tenantId);
            return ['knowledge_base_id' => $kb?->id, 'kb_name' => $kb?->name, 'reason' => 'fallback_default_no_hits'];
        }

        // Mappa document_id -> knowledge_base_id
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $hits)));
        $rows = DB::table('documents')
            ->select(['id', 'knowledge_base_id', 'source', 'title'])
            ->whereIn('id', $docIds)
            ->where('tenant_id', $tenantId)
            ->get();
        $docMeta = [];
        foreach ($rows as $r) {
            $docMeta[(int) $r->id] = [
                'kb' => (int) ($r->knowledge_base_id ?? 0),
                'source' => (string) ($r->source ?? ''),
                'title' => (string) ($r->title ?? ''),
            ];
        }

        $scoreByKb = [];
        // Heuristics: boost upload/doc titles when query mentions specific concepts/location
        $queryLower = mb_strtolower($normalizedQuery);
        $mentionsCommerciali = str_contains($queryLower, 'attivita commerciali')
            || str_contains($queryLower, 'attività commerciali')
            || str_contains($queryLower, 'negozi');
        $mentionsSanCesareo = str_contains($queryLower, 'san cesareo');
        foreach ($hits as $h) {
            $docId = (int) $h['document_id'];
            $meta = $docMeta[$docId] ?? null;
            $kbId = $meta['kb'] ?? 0;
            if ($kbId <= 0) { continue; }
            // Applica boost factor BM25 (dal config admin)
            $boostMultiplier = $bm25BoostFactor;
            // Boost documenti caricati manualmente (configurabile)
            if (!empty($meta['source']) && $meta['source'] === 'upload' && $uploadBoost > 0) {
                $boostMultiplier *= $uploadBoost;
            }
            // Boost per keyword nel titolo (configurabili)
            $titleLower = mb_strtolower((string) ($meta['title'] ?? ''));
            foreach ($titleKeywordBoosts as $kw => $factor) {
                $kw = mb_strtolower((string) $kw);
                $f = (float) $factor;
                if ($kw !== '' && $f > 0 && str_contains($titleLower, $kw)) {
                    $boostMultiplier *= $f;
                }
            }
            // Boost per location presenti nella query (configurabili)
            foreach ($locationBoosts as $loc => $factor) {
                $loc = mb_strtolower((string) $loc);
                $f = (float) $factor;
                if ($loc !== '' && $f > 0 && str_contains($queryLower, $loc)) {
                    $boostMultiplier *= $f;
                }
            }
            $boostedScore = (float) $h['score'] * $boostMultiplier;
            $scoreByKb[$kbId] = ($scoreByKb[$kbId] ?? 0.0) + $boostedScore;
        }

        if ($scoreByKb === []) {
            $kb = $this->getDefaultKb($tenantId);
            return ['knowledge_base_id' => $kb?->id, 'kb_name' => $kb?->name, 'reason' => 'fallback_default_no_kb_mapping'];
        }

        arsort($scoreByKb);
        
        // 🔍 Verifica che la KB selezionata abbia effettivamente documenti
        foreach ($scoreByKb as $kbId => $score) {
            $kbDocCount = DB::table('documents')
                ->where('tenant_id', $tenantId)
                ->where('knowledge_base_id', $kbId)
                ->count();
                
            if ($kbDocCount > 0) {
                $bestKb = KnowledgeBase::query()->where('tenant_id', $tenantId)->find($kbId);
                
                // 🔍 LOG: Dettagli selezione KB con validazione
                \Log::info('KB Selection Result', [
                    'tenant_id' => $tenantId,
                    'query' => $query,
                    'selected_kb_id' => $bestKb?->id,
                    'selected_kb_name' => $bestKb?->name,
                    'mode' => $mode,
                    'bm25_boost_applied' => $bm25BoostFactor,
                    'score_by_kb' => $scoreByKb,
                    'total_hits' => count($hits),
                    'kb_doc_count' => $kbDocCount,
                    'reason' => 'bm25_with_validation'
                ]);
                
                return [
                    'knowledge_base_id' => $bestKb?->id,
                    'kb_name' => $bestKb?->name,
                    'reason' => 'bm25_aggregate_score_validated',
                ];
            }
        }
        
        // Se tutte le KB con score hanno 0 documenti, vai in fallback
        $kb = $this->getDefaultKb($tenantId);
        \Log::info('KB Selection Fallback', [
            'tenant_id' => $tenantId,
            'query' => $query,
            'reason' => 'all_scored_kbs_empty',
            'fallback_kb_id' => $kb?->id,
            'fallback_kb_name' => $kb?->name
        ]);
        
        return ['knowledge_base_id' => $kb?->id, 'kb_name' => $kb?->name, 'reason' => 'fallback_scored_kbs_empty'];
    }

    private function getDefaultKb(int $tenantId): ?KnowledgeBase
    {
        $kb = KnowledgeBase::query()->where('tenant_id', $tenantId)->where('is_default', true)->first();
        if ($kb) return $kb;
        $kb = KnowledgeBase::query()->where('tenant_id', $tenantId)->orderBy('id')->first();
        return $kb;
    }

    /**
     * Normalizza una query per la selezione della KB rimuovendo elementi che possono
     * interferire con la corretta identificazione della KB più rilevante.
     *
     * @param string $query Query originale dell'utente
     * @return string Query normalizzata per la selezione KB
     */
    private function normalizeQueryForKbSelection(string $query): string
    {
        // Step 1: Rimuovi parole di contesto comuni che non aggiungono valore semantico
        $stopWordsPattern = [
            '/\b(cos\'è|cosa è|che cos\'è|che cosa è)\b/iu',    // Parole interrogative
            '/\b(cos\'è|cosa è|che cos\'è|che cosa è)\s*/iu',   // Con spazi
            '/\b(dimmi|spiegami|parlami)\b/iu',                 // Verbi di richiesta
            '/\b(di|del|della|dei|delle)\b/iu',                // Preposizioni semplici
            '/\b(il|la|lo|gli|le|un|una|uno)\b/iu',           // Articoli
            '/\b(come|quando|dove|perché|perchè)\b/iu',        // Altre interrogative
            '/\b(vorrei sapere|mi puoi dire|puoi dirmi)\b/iu', // Formule di cortesia
            '/\b(iniziativa|progetto|programma)\b/iu',         // Parole generiche che causano confusione
            '/\b(che|che cosa|quale)\b/iu',                    // Altri interrogativi
        ];
        
        $normalized = $query;
        foreach ($stopWordsPattern as $pattern) {
            $normalized = preg_replace($pattern, ' ', $normalized);
        }
        
        // Step 2: Rimuovi punteggiatura che può interferire con BM25
        $normalized = str_replace(['"', "'", '`'], '', $normalized);      // Virgolette semplici
        $normalized = preg_replace('/[?.!;,:]/', ' ', $normalized);       // Punteggiatura standard
        
        // Step 3: Normalizza spazi multipli e trim
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        // Step 4: Se la query è diventata troppo corta o vuota, usa l'originale
        if (mb_strlen($normalized) < 3) {
            return $query;
        }
        
        return $normalized;
    }
}


