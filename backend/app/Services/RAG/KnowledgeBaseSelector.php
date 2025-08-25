<?php

namespace App\Services\RAG;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class KnowledgeBaseSelector
{
    public function __construct(private readonly TextSearchService $text)
    {
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
        // Boost per keyword specifiche SOW
        $sowKeywords = ['sow', 'statement of work', 'contratto quadro', 'contratti quadro', 'servizi it'];
        $queryLower = mb_strtolower($query);
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

        // Ridotto da 200 a 50 per evitare diluizione score
        $hits = $this->text->searchTopK($tenantId, $query, 50, null);
        if ($hits === []) {
            $kb = $this->getDefaultKb($tenantId);
            return ['knowledge_base_id' => $kb?->id, 'kb_name' => $kb?->name, 'reason' => 'fallback_default_no_hits'];
        }

        // Mappa document_id -> knowledge_base_id
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $hits)));
        $rows = DB::table('documents')
            ->select(['id', 'knowledge_base_id'])
            ->whereIn('id', $docIds)
            ->where('tenant_id', $tenantId)
            ->get();
        $docToKb = [];
        foreach ($rows as $r) {
            $docToKb[(int) $r->id] = (int) ($r->knowledge_base_id ?? 0);
        }

        $scoreByKb = [];
        foreach ($hits as $h) {
            $kbId = $docToKb[(int) $h['document_id']] ?? 0;
            if ($kbId <= 0) { continue; }
            // somma score RRF/FTS
            $scoreByKb[$kbId] = ($scoreByKb[$kbId] ?? 0.0) + (float) $h['score'];
        }

        if ($scoreByKb === []) {
            $kb = $this->getDefaultKb($tenantId);
            return ['knowledge_base_id' => $kb?->id, 'kb_name' => $kb?->name, 'reason' => 'fallback_default_no_kb_mapping'];
        }

        arsort($scoreByKb);
        $bestKbId = (int) array_key_first($scoreByKb);
        $bestKb = KnowledgeBase::query()->where('tenant_id', $tenantId)->find($bestKbId);
        return [
            'knowledge_base_id' => $bestKb?->id,
            'kb_name' => $bestKb?->name,
            'reason' => 'bm25_aggregate_score',
        ];
    }

    private function getDefaultKb(int $tenantId): ?KnowledgeBase
    {
        $kb = KnowledgeBase::query()->where('tenant_id', $tenantId)->where('is_default', true)->first();
        if ($kb) return $kb;
        $kb = KnowledgeBase::query()->where('tenant_id', $tenantId)->orderBy('id')->first();
        return $kb;
    }
}


