<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIEmbeddingsService;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class KbSearchService
{
    public function __construct(
        private readonly OpenAIEmbeddingsService $embeddings,
        private readonly MilvusClient $milvus,
        private readonly TextSearchService $text,
        private readonly RerankerInterface $reranker = new EmbeddingReranker(new \App\Services\LLM\OpenAIEmbeddingsService()),
        private readonly ?MultiQueryExpander $mq = null,
        private readonly RagCache $cache = new RagCache(),
        private readonly RagTelemetry $telemetry = new RagTelemetry(),
        private readonly KnowledgeBaseSelector $kbSelector = new KnowledgeBaseSelector(new TextSearchService()),
    ) {}

    // Lingue attive per il tenant corrente (codici ISO, lowercase)
    private array $activeLangs = ['it'];

    /**
     * @param int   $tenantId
     * @param string $query
     * @param int   $limit   Numero massimo di documenti da citare
     * @param array $opts    ['top_k' => int, 'mmr_lambda' => float]
     */
    public function retrieve(int $tenantId, string $query, bool $debug = false): array
    {
        if ($query === '') {
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $debug ? [] : null];
        }

        $this->activeLangs = $this->getTenantLanguages($tenantId);

        // Determina l'intent primario più specifico
        $intents = $this->detectIntents($query, $tenantId);
        $intentDebug = null;
        
        if ($debug) {
            // Calcola scores per debug
            $q = mb_strtolower($query);
            $expandedQ = $this->expandQueryWithSynonyms($q, $tenantId);
            $intentDebug = [
                'original_query' => $query,
                'lowercased_query' => $q,
                'expanded_query' => $expandedQ,
                'intents_detected' => $intents,
                'intent_scores' => [
                    'thanks' => $this->scoreIntent($q, $expandedQ, $this->keywordsThanks($this->activeLangs)),
                    'schedule' => $this->scoreIntent($q, $expandedQ, $this->keywordsSchedule($this->activeLangs)),
                    'address' => $this->scoreIntent($q, $expandedQ, $this->keywordsAddress($this->activeLangs)),
                    'email' => $this->scoreIntent($q, $expandedQ, $this->keywordsEmail($this->activeLangs)),
                    'phone' => $this->scoreIntent($q, $expandedQ, $this->keywordsPhone($this->activeLangs)),
                ],
                'keywords_matched' => $this->getMatchedKeywords($q, $expandedQ),
            ];
        }
        
        // Esegui l'intent con score più alto (già ordinati per priorità in detectIntents)
        foreach ($intents as $intentType) {
            // Selezione KB prima del lookup intent (per scoping)
            $kbSelIntent = $this->kbSelector->selectForQuery($tenantId, $query);
            $selectedKbIdIntent = $kbSelIntent['knowledge_base_id'] ?? null;
            $result = $this->executeIntent($intentType, $tenantId, $query, $debug, $selectedKbIdIntent);
            if ($result !== null) {
                // Aggiungi debug intent se disponibile
                if ($intentDebug) {
                    $intentDebug['executed_intent'] = isset($result['debug']['semantic_fallback']) ? $intentType . '_semantic' : $intentType;
                    $result['debug']['intent_detection'] = $intentDebug;
                    $result['debug']['selected_kb'] = $kbSelIntent;
                }
                return $result;
            }
        }

        $cfg = (array) config('rag.hybrid');
        $vecTopK   = (int) ($cfg['vector_top_k'] ?? 30);
        $bmTopK    = (int) ($cfg['bm25_top_k']   ?? 50);
        $rrfK      = (int) ($cfg['rrf_k']        ?? 60);
        $mmrLambda = (float) ($cfg['mmr_lambda'] ?? 0.3);
        $mmrTake   = (int) ($cfg['mmr_take']     ?? 8);
        $neighbor  = (int) ($cfg['neighbor_radius'] ?? 1);

        // Multi-query expansion (originale + parafrasi)
        $queries = $this->mq ? $this->mq->expand($query) : [$query];
        $this->telemetry->event('mq.expanded', ['tenant_id'=>$tenantId,'query'=>$query,'variants'=>$queries]);
        $allFused = [];
        $trace = $debug ? ['queries' => $queries] : null;
        if ($debug) {
            $trace['milvus'] = $this->milvus->health();
        }
        // Selezione automatica KB per la query
        $kbSel = $this->kbSelector->selectForQuery($tenantId, $query);
        $selectedKbId = $kbSel['knowledge_base_id'] ?? null;
        $selectedKbName = $kbSel['kb_name'] ?? null;
        if ($debug) {
            $trace['selected_kb'] = $kbSel;
        }

        foreach ($queries as $q) {
            if ($debug) {
                $qEmb = $this->embeddings->embedTexts([$q])[0] ?? null;
                $vecHit = [];
                if ((($trace['milvus']['ok'] ?? false) === true) && $qEmb) {
                    $vecHit = $this->milvus->searchTopK($tenantId, $qEmb, $vecTopK); // filtro KB lato app più avanti
                }
                $bmHit  = $this->text->searchTopK($tenantId, $q, $bmTopK, $selectedKbId);
                $trace['per_query'][] = [
                    'q' => $q,
                    'vector_hits' => array_slice($vecHit, 0, 10),
                    'fts_hits' => array_slice($bmHit, 0, 10),
                ];
                $allFused[] = $this->rrfFuse($this->filterVecHitsByKb($vecHit, $selectedKbId), $bmHit, $rrfK);
            } else {
                $key = 'rag:vecfts:'.$tenantId.':'.sha1($q).":{$vecTopK},{$bmTopK},{$rrfK}";
                $list = $this->cache->remember($key, function () use ($tenantId, $q, $vecTopK, $bmTopK, $rrfK) {
                    $qEmb = $this->embeddings->embedTexts([$q])[0] ?? null;
                    $vecHit = [];
                    $milvusHealth = $this->milvus->health();
                    if (($milvusHealth['ok'] ?? false) === true && $qEmb) {
                        $vecHit = $this->milvus->searchTopK($tenantId, $qEmb, $vecTopK);
                    }
                    // Nota: non passiamo $selectedKbId qui perché la closure non lo ha in scope; il key cache non cambia con KB.
                    // Pertanto, applichiamo filtro KB al risultato fuso lato chiamante.
                    $bmHit  = $this->text->searchTopK($tenantId, $q, $bmTopK, null);
                    return $this->rrfFuse($vecHit, $bmHit, $rrfK);
                });
                // Applica filtro KB ai risultati fusi
                $allFused[] = $this->filterFusedByKb($list, $selectedKbId);
            }
        }
        // Fusione finale tra tutte le query (RRF su posizioni già “scorate”):
        $fused = $this->rrfFuseMany($allFused, $rrfK);
        if ($debug) { $trace['fused_top'] = array_slice($fused, 0, 20); }
        if ($fused === []) {
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $trace];
        }

        // Prepara candidati per reranker (top_n configurabile)
        $topN = (int) config('rag.reranker.top_n', 30);
        $candidates = [];
        foreach (array_slice($fused, 0, $topN) as $h) {
            $candidates[] = [
                'document_id' => (int) $h['document_id'],
                'chunk_index' => (int) $h['chunk_index'],
                'text' => $this->text->getChunkSnippet((int)$h['document_id'], (int)$h['chunk_index'], 512) ?? '',
                'score' => (float) $h['score'],
            ];
        }

        // Se configurato, usa Cohere; altrimenti embedding reranker
        $driver = (string) config('rag.reranker.driver', 'embedding');
        $reranker = $driver === 'cohere' ? new CohereReranker() : new EmbeddingReranker($this->embeddings);
        $ranked = $this->cache->remember('rag:rerank:'.sha1($query).":{$tenantId},{$driver},{$topN}", function () use ($reranker, $query, $candidates, $topN) {
            return $reranker->rerank($query, $candidates, $topN);
        });
        $this->telemetry->event('rerank.done', ['tenant_id'=>$tenantId,'driver'=>$driver,'in'=>count($candidates),'out'=>count($ranked)]);
        if ($debug) { $trace['reranked_top'] = array_slice($ranked, 0, 20); }

        // Ora calcola MMR sugli embedding dei candidati rerankati
        $qEmb = $this->embeddings->embedTexts([$query])[0] ?? null;
        $texts = array_map(fn($c) => (string)$c['text'], $ranked);
        $docEmb = $texts ? $this->embeddings->embedTexts($texts) : [];
        $selIdx = $this->mmr($qEmb, $docEmb, $mmrLambda, $mmrTake);
        if ($debug) { $trace['mmr_selected_idx'] = $selIdx; }

        $seen = [];
        $cits = [];
        foreach ($selIdx as $i) {
            $base = $ranked[$i] ?? null;
            if ($base === null) { continue; }
            $docId = (int) $base['document_id'];
            if (isset($seen[$docId])) continue;
            $seen[$docId] = true;

            $snippet = $this->text->getChunkSnippet($docId, (int)$base['chunk_index'], 400) ?? '';
            for ($d = -$neighbor; $d <= $neighbor; $d++) {
                if ($d === 0) continue;
                $s2 = $this->text->getChunkSnippet($docId, (int)$base['chunk_index'] + $d, 200);
                if ($s2) $snippet .= "\n".$s2;
            }

            $doc = DB::selectOne('SELECT id, title, path FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$docId, $tenantId]);
            if (!$doc) continue;
            $cits[] = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $snippet,
                'score' => (float) $base['score'],
                'knowledge_base' => $selectedKbName,
            ];
        }

        $result = [
            'citations' => $cits,
            'confidence' => $this->confidence($fused),
            'debug' => $trace,
        ];
        
        // Aggiungi debug intent anche per il RAG normale
        if ($intentDebug) {
            $intentDebug['executed_intent'] = 'hybrid_rag';
            $result['debug']['intent_detection'] = $intentDebug;
        }
        
        return $result;
    }

    private function filterVecHitsByKb(array $vecHits, ?int $kbId): array
    {
        if ($kbId === null) return $vecHits;
        if ($vecHits === []) return $vecHits;
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $vecHits)));
        $rows = DB::table('documents')->select(['id','knowledge_base_id'])->whereIn('id', $docIds)->get();
        $docToKb = [];
        foreach ($rows as $r) { $docToKb[(int)$r->id] = (int) ($r->knowledge_base_id ?? 0); }
        return array_values(array_filter($vecHits, fn($h) => ($docToKb[(int)$h['document_id']] ?? 0) === $kbId));
    }

    private function filterFusedByKb(array $fused, ?int $kbId): array
    {
        if ($kbId === null) return $fused;
        if ($fused === []) return $fused;
        $docIds = array_values(array_unique(array_map(fn($h) => (int) $h['document_id'], $fused)));
        $rows = DB::table('documents')->select(['id','knowledge_base_id'])->whereIn('id', $docIds)->get();
        $docToKb = [];
        foreach ($rows as $r) { $docToKb[(int)$r->id] = (int) ($r->knowledge_base_id ?? 0); }
        return array_values(array_filter($fused, fn($h) => ($docToKb[(int)$h['document_id']] ?? 0) === $kbId));
    }

    

    /**
     * Semplice MMR greedy su embeddings (cosine similarity)
     * @param array $queryEmbedding float[]
     * @param array $docEmbeddings array<float[]>
     * @return array indici selezionati
     */
    private function mmr(?array $queryEmbedding, array $docEmbeddings, float $lambda, int $k): array
    {
        if (!$queryEmbedding || $docEmbeddings === []) return array_slice(range(0, count($docEmbeddings)-1), 0, $k);
        $selected = [];
        $candidates = range(0, count($docEmbeddings) - 1);
        $queryNorm = $this->l2norm($queryEmbedding);
        $docNorms = array_map(fn ($v) => $this->l2norm($v), $docEmbeddings);

        while (count($selected) < $k && $candidates !== []) {
            $bestIdx = null;
            $bestScore = -INF;
            foreach ($candidates as $i) {
                $simToQuery = $this->cosine($queryEmbedding, $docEmbeddings[$i], $queryNorm, $docNorms[$i]);
                $maxSimToSelected = 0.0;
                foreach ($selected as $j) {
                    $sim = $this->cosine($docEmbeddings[$i], $docEmbeddings[$j], $docNorms[$i], $docNorms[$j]);
                    if ($sim > $maxSimToSelected) { $maxSimToSelected = $sim; }
                }
                $score = $lambda * $simToQuery - (1.0 - $lambda) * $maxSimToSelected;
                if ($score > $bestScore) { $bestScore = $score; $bestIdx = $i; }
            }
            if ($bestIdx === null) break;
            $selected[] = $bestIdx;
            $candidates = array_values(array_diff($candidates, [$bestIdx]));
        }
        return $selected;
    }

    private function l2norm(array $v): float
    {
        $s = 0.0;
        foreach ($v as $x) {
            $s += $x * $x;
        }
        return (float) sqrt(max($s, 1e-12));
    }

    private function cosine(array $a, array $b, ?float $nA = null, ?float $nB = null): float
    { $nA = $nA ?? $this->l2norm($a); $nB = $nB ?? $this->l2norm($b); $dot=0.0; $n=min(count($a),count($b)); for($i=0;$i<$n;$i++){ $dot += ((float)$a[$i])*((float)$b[$i]); } return $dot / max($nA*$nB,1e-12); }

    private function rrfFuse(array $vecHits, array $bmHits, int $k): array
    {
        $key = fn($h) => $h['document_id'].':'.$h['chunk_index'];
        $score = [];
        $r=0; foreach($vecHits as $h){ $r++; $score[$key($h)] = ($score[$key($h)] ?? 0) + 1.0/($k+$r); }
        $r=0; foreach($bmHits as $h){  $r++; $score[$key($h)] = ($score[$key($h)] ?? 0) + 1.0/($k+$r); }
        $out = [];
        foreach ($score as $kk=>$s){ [$d,$i] = array_map('intval', explode(':',$kk,2)); $out[] = ['document_id'=>$d,'chunk_index'=>$i,'score'=>$s]; }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private function rrfFuseMany(array $lists, int $k): array
    {
        $score = [];
        foreach ($lists as $list) {
            $rank = 0;
            foreach ($list as $h) {
                $rank++;
                $key = $h['document_id'].':'.$h['chunk_index'];
                $score[$key] = ($score[$key] ?? 0) + 1.0/($k+$rank);
            }
        }
        $out = [];
        foreach ($score as $kk => $s) {
            [$d,$i] = array_map('intval', explode(':', $kk, 2));
            $out[] = ['document_id'=>$d,'chunk_index'=>$i,'score'=>$s];
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return $out;
    }

    private function confidence(array $fused): float
    { $top = array_slice($fused,0,3); $sum = array_sum(array_map(fn($h)=>(float)$h['score'],$top)); return max(0.0,min(1.0,$sum*10)); }

    // Lingue configurate a livello di tenant; default ['it'] se assenti
    private function getTenantLanguages(int $tenantId): array
    {
        $t = Tenant::query()->select(['id','languages','default_language'])->find($tenantId);
        $langs = array_values(array_filter(array_map('strtolower', (array) ($t->languages ?? []))));
        return $langs === [] ? ['it'] : $langs;
    }

    private function keywordsThanks(array $langs): array
    {
        $it = [
            // Ringraziamenti diretti (alta priorità)
            'grazie','grazie mille','ti ringrazio','la ringrazio','vi ringrazio','molte grazie',
            'tante grazie','grazie tante','grazie di tutto','grazie per tutto','grazie dell\'aiuto',
            'grazie per l\'aiuto','grazie per la risposta','perfetto grazie','ok grazie',
            // Apprezzamenti
            'ottimo','perfetto','bene','molto bene','eccellente','fantastico',
            'sei stato di aiuto','sei stata utile','molto utile','molto gentile',
            // Formule di cortesia
            'la ringrazio sentitamente','cordiali ringraziamenti','distinti saluti',
            'cordiali saluti','buona giornata','buon lavoro','arrivederci'
        ];
        $en = [
            'thanks','thank you','thank you very much','thanks a lot','many thanks',
            'thanks so much','appreciate it','much appreciated','perfect thanks',
            'great thanks','excellent','perfect','awesome','wonderful',
            'you\'ve been helpful','very helpful','very kind','good job',
            'have a nice day','goodbye','bye','see you'
        ];
        $es = [
            'gracias','muchas gracias','mil gracias','te agradezco','perfecto gracias',
            'excelente','fantástico','muy bien','muy útil','muy amable',
            'que tengas buen día','adiós','hasta luego'
        ];
        $fr = [
            'merci','merci beaucoup','mille mercis','je vous remercie','parfait merci',
            'excellent','fantastique','très bien','très utile','très gentil',
            'bonne journée','au revoir','à bientôt'
        ];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsSchedule(array $langs): array
    {
        $it = [
            // Termini specifici orari (alta priorità)
            'orario','orari','orario di apertura','orari di apertura','quando è aperto','quando apre',
            'quando chiude','a che ora','apertura','chiusura','aperto','chiuso','ore',
            // Giorni e periodi
            'lunedì','martedì','mercoledì','giovedì','venerdì','sabato','domenica',
            'festivi','feriali','weekend','mattina','pomeriggio','sera',
            // Termini di servizio
            'ricevimento','sportello','ufficio orari','servizio clienti'
        ];
        $en = ['schedule','hours','opening hours','opening times','when open','when does it open','what time','open','closed','business hours'];
        $es = ['horario','horarios','horas de apertura','cuándo abre','cuándo cierra','abierto','cerrado'];
        $fr = ['horaires','heures d\'ouverture','quand ouvert','quand ouvre','ouvert','fermé'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsPhone(array $langs): array
    {
        $it = [
            // Termini specifici telefono (alta priorità)
            'telefono','numero di telefono','numero','tel','cellulare','cell','recapito telefonico',
            'contatto telefonico','mobile','fisso','centralino','chiamare','telefonare',
            // Termini di emergenza
            'emergenza','pronto soccorso','118','112','113','115','117','protezione civile',
            // Termini generici (bassa priorità)
            'contatto','contatti','recapito'
        ];
        $en = ['phone','telephone','phone number','number','tel','cell','cellphone','mobile','contact','contacts','landline'];
        $es = ['telefono','teléfono','numero','número de teléfono','movil','móvil','celular','contacto'];
        $fr = ['téléphone','numéro','numéro de téléphone','portable','mobile','contact'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsEmail(array $langs): array
    {
        $it = ['email','e-mail','mail','posta','posta elettronica','indirizzo email','indirizzo e-mail','pec'];
        $en = ['email','e-mail','mail','email address'];
        $es = ['correo','correo electrónico','email','e-mail','mail'];
        $fr = ['email','e-mail','courriel','adresse mail'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function keywordsAddress(array $langs): array
    {
        $it = [
            // Termini specifici indirizzo (alta priorità)
            'indirizzo','residenza','domicilio','sede','ubicazione','dove si trova','dove è',
            'via','viale','piazza','corso','largo','vicolo','strada','p.zza',
            // Termini di localizzazione
            'posizione','località','zona','quartiere','dove','ufficio'
        ];
        $en = ['address','residence','headquarters','office','location','street','st.','avenue','ave','road','rd','square','lane','boulevard','blvd'];
        $es = ['direccion','dirección','domicilio','sede','ubicación','calle','avenida','av.','plaza','carretera'];
        $fr = ['adresse','résidence','domicile','siège','emplacement','rue','avenue','boulevard','place'];
        return $this->mergeLangKeywords($langs, compact('it','en','es','fr'));
    }

    private function removalPhrases(array $langs): array
    {
        $common = ['di','del','della','dello','dei','degli','delle','il','lo','la','i','gli','le','per favore','perfavore','per cortesia','grazie'];
        $it = ['mi puoi dire','mi sai dire','puoi dirmi','potresti dirmi','sapresti','mi trovi','mostrami','dimmi','indicami','fornisci','dammi','trova','cerca','qual è','numero di telefono','telefono','tel','cellulare','recapito','contatto','contatti','email','e-mail','mail','posta','pec','indirizzo','residenza','sede','via','viale','piazza','corso','largo','vicolo','mobile','fisso'];
        $en = ['can you tell me','could you tell me','would you tell me','please tell me','tell me','show me','find','search','what is','what\'s','phone number','phone','telephone','tel','cell','mobile','contact','email','email address','address','residence','headquarters','office','street','avenue','road'];
        $es = ['me puedes decir','podrías decirme','dime','muéstrame','encuentra','busca','cuál es','número de teléfono','teléfono','móvil','celular','contacto','correo','correo electrónico','dirección','calle','avenida','plaza'];
        $fr = ['peux-tu me dire','pourrais-tu me dire','dis-moi','montre-moi','trouve','recherche','quel est','numéro de téléphone','téléphone','portable','mobile','contact','courriel','adresse','rue','avenue','boulevard'];
        $map = compact('it','en','es','fr');
        $phr = $common;
        foreach ($langs as $l) { $phr = array_merge($phr, $map[$l] ?? []); }
        $phr = array_values(array_unique($phr));
        usort($phr, fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        return $phr;
    }

    private function mergeLangKeywords(array $langs, array $dict): array
    {
        $out = [];
        foreach ($langs as $l) { $out = array_merge($out, $dict[$l] ?? []); }
        if ($out === []) { $out = $dict['it'] ?? []; }
        $out = array_values(array_unique($out));
        usort($out, fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
        return $out;
    }

    /**
     * Rileva tutti gli intent possibili in una query, ordinati per priorità
     */
    private function detectIntents(string $query, int $tenantId = null): array
    {
        $q = mb_strtolower($query);
        $expandedQ = $this->expandQueryWithSynonyms($q, $tenantId);
        $intents = [];
        
        // Ottieni configurazione intent del tenant
        $enabledIntents = $this->getTenantEnabledIntents($tenantId);
        $extraKeywords = $this->getTenantExtraKeywords($tenantId);
        
        // Score per ogni intent basato su specificitá keywords
        $scores = [];
        $allIntentTypes = ['thanks', 'schedule', 'address', 'email', 'phone'];
        
        foreach ($allIntentTypes as $intentType) {
            if (!($enabledIntents[$intentType] ?? true)) {
                continue; // Skip intent disabilitati
            }
            
            $keywords = $this->getIntentKeywords($intentType, $this->activeLangs);
            
            // Aggiungi keyword extra se configurate
            if (!empty($extraKeywords[$intentType])) {
                $keywords = array_merge($keywords, (array) $extraKeywords[$intentType]);
            }
            
            $scores[$intentType] = $this->scoreIntent($q, $expandedQ, $keywords);
        }
        
        // Filtra e ordina per score (più alto = più specifico)
        arsort($scores);
        foreach ($scores as $intent => $score) {
            if ($score > 0) {
                $intents[] = $intent;
            }
        }
        
        return $intents;
    }
    
    /**
     * Calcola score di specificitá per un intent
     */
    private function scoreIntent(string $query, string $expandedQuery, array $keywords): float
    {
        $score = 0.0;
        $queryLen = mb_strlen($query);
        
        foreach ($keywords as $kw) {
            if (str_contains($query, $kw)) {
                // Score più alto per keyword più lunghe e specifiche
                $keywordScore = mb_strlen($kw) / max($queryLen, 1);
                $score += $keywordScore;
            } elseif (str_contains($expandedQuery, $kw)) {
                // Score più basso per match su query espansa
                $keywordScore = (mb_strlen($kw) / max($queryLen, 1)) * 0.5;
                $score += $keywordScore;
            }
        }
        
        return $score;
    }
    
    /**
     * Esegue un intent specifico
     */
    private function executeIntent(string $intentType, int $tenantId, string $query, bool $debug, ?int $knowledgeBaseId = null): ?array
    {
        $name = $this->extractNameFromQuery($query, $intentType);
        
        // Espandi il nome con sinonimi per migliorare la ricerca
        $expandedName = $this->expandNameWithSynonyms($name, $tenantId);
        
        switch ($intentType) {
            case 'thanks':
                // Intent speciale: restituisce direttamente una risposta cortese senza cercare documenti
                return $this->executeThanksIntent($tenantId, $query, $debug);
            case 'schedule':
                $results = $this->text->findSchedulesNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'schedule';
                break;
            case 'phone':
                $results = $this->text->findPhonesNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'phone';
                break;
            case 'email':
                $results = $this->text->findEmailsNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'email';
                break;
            case 'address':
                $results = $this->text->findAddressesNearName($tenantId, $expandedName, 5, $knowledgeBaseId);
                $field = 'address';
                break;
            default:
                return null;
        }
        
        // Se la ricerca specifica non trova nulla, prova ricerca semantica con sinonimi
        if ($results === []) {
            $semanticResults = $this->executeSemanticFallback($intentType, $tenantId, $name, $query, $debug, $knowledgeBaseId);
            if ($semanticResults !== null) {
                return $semanticResults;
            }
            return null;
        }
        
        $cits = [];
        foreach ($results as $r) {
            $snippet = (string) ($r['excerpt'] ?? '') ?: ($this->text->getChunkSnippet((int)$r['document_id'], (int)$r['chunk_index'], 400) ?? '');
            $doc = DB::selectOne('SELECT id, title, path FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$r['document_id'], $tenantId]);
            if (!$doc) { continue; }
            
            $citation = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $snippet,
                'score' => (float) $r['score'],
            ];
            
            // Aggiungi il campo specifico dell'intent
            $citation[$field] = (string) $r[$field];
            $cits[] = $citation;
        }
        
        return [
            'citations' => $cits,
            'confidence' => 1.0,
            'debug' => $debug ? ["{$intentType}_lookup" => $results] : null,
        ];
    }
    
    /**
     * Estrae il nome dalla query con rimozione keywords specifiche per intent
     */
    private function extractNameFromQuery(string $query, string $intentType): string
    {
        $s = mb_strtolower(trim($query));
        // Rimuove punteggiatura comune
        $s = preg_replace('/[\?\.!,:;\-"""\'\'`]/u', ' ', $s);
        
        // Rimuove keywords specifiche dell'intent oltre alle generali
        $intentKeywords = [];
        switch ($intentType) {
            case 'schedule':
                $intentKeywords = ['orario', 'orari', 'quando', 'apertura', 'chiusura', 'aperto', 'chiuso', 'ore'];
                break;
            case 'phone':
                $intentKeywords = ['telefono', 'numero', 'tel', 'cellulare', 'mobile', 'fisso', 'contatto', 'recapito'];
                break;
            case 'email':
                $intentKeywords = ['email', 'e-mail', 'mail', 'posta', 'pec'];
                break;
            case 'address':
                $intentKeywords = ['indirizzo', 'residenza', 'domicilio', 'sede', 'ubicazione'];
                break;
        }
        
        $phrases = array_merge($this->removalPhrases($this->activeLangs), $intentKeywords);
        if ($phrases !== []) {
            $escaped = array_map(fn($p) => preg_quote($p, '/'), $phrases);
            $pattern = '/\b(' . implode('|', $escaped) . ')\b/u';
            $s = preg_replace($pattern, ' ', $s);
        }
        
        // Collassa spazi
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string) $s);
    }
    
    /**
     * Trova le keywords che hanno fatto match per ogni intent
     */
    private function getMatchedKeywords(string $query, string $expandedQuery): array
    {
        $matched = [
            'thanks' => [],
            'schedule' => [],
            'address' => [],
            'email' => [],
            'phone' => [],
        ];
        
        $allKeywords = [
            'thanks' => $this->keywordsThanks($this->activeLangs),
            'schedule' => $this->keywordsSchedule($this->activeLangs),
            'address' => $this->keywordsAddress($this->activeLangs),
            'email' => $this->keywordsEmail($this->activeLangs),
            'phone' => $this->keywordsPhone($this->activeLangs),
        ];
        
        foreach ($allKeywords as $intent => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($query, $kw)) {
                    $matched[$intent][] = $kw . ' (direct)';
                } elseif (str_contains($expandedQuery, $kw)) {
                    $matched[$intent][] = $kw . ' (expanded)';
                }
            }
        }
        
        return $matched;
    }
    
    /**
     * Espande il nome estratto con sinonimi per migliorare la ricerca nei documenti
     */
    private function expandNameWithSynonyms(string $name, ?int $tenantId = null): string
    {
        $synonyms = $this->getTenantSynonyms($tenantId);
        
        $expanded = $name;
        foreach ($synonyms as $term => $synonymList) {
            if (str_contains($name, $term)) {
                // Aggiungi i sinonimi separati da spazi per la ricerca
                $expanded .= ' ' . $synonymList;
            }
        }
        
        return $expanded;
    }
    
    /**
     * Espande la query con sinonimi comuni per migliorare l'intent detection
     */
    private function expandQueryWithSynonyms(string $query, ?int $tenantId = null): string
    {
        $synonyms = $this->getTenantSynonyms($tenantId);

        $expanded = $query;
        foreach ($synonyms as $term => $synonymList) {
            if (str_contains($query, $term)) {
                $expanded .= ' ' . $synonymList;
            }
        }

        return $expanded;
    }
    
    /**
     * Fallback semantico: cerca con embedding usando query espansa e lascia che LLM disambigui
     */
    private function executeSemanticFallback(string $intentType, int $tenantId, string $name, string $originalQuery, bool $debug, ?int $knowledgeBaseId = null): ?array
    {
        // Costruisci query semantica combinando termine originale + sinonimi
        $semanticQuery = $this->buildSemanticQuery($name, $intentType, $tenantId);
        
        $debugInfo = [
            'semantic_fallback' => [
                'original_name' => $name,
                'semantic_query' => $semanticQuery,
                'intent_type' => $intentType,
            ]
        ];
        
        // Ricerca semantica con Milvus usando query espansa
        $embedding = $this->embeddings->embedTexts([$semanticQuery])[0] ?? null;
        if (!$embedding) {
            return null;
        }
        
        $milvusHealth = $this->milvus->health();
        if (!($milvusHealth['ok'] ?? false)) {
            return null;
        }
        
        // Prendi più risultati per compensare la ricerca più ampia
        $semanticHits = $this->milvus->searchTopK($tenantId, $embedding, 100);
        
        $debugInfo['semantic_fallback']['semantic_results_found'] = count($semanticHits);
        $debugInfo['semantic_fallback']['top_semantic_hits'] = array_slice($semanticHits, 0, 5);
        
        if (empty($semanticHits)) {
            $debugInfo['semantic_fallback']['failure_reason'] = 'No semantic hits from Milvus';
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $debug ? $debugInfo : null];
        }
        
        // Filtra i risultati per estrarre solo quelli che contengono informazioni del tipo richiesto
        $filteredResults = $this->extractIntentDataFromSemanticResults($semanticHits, $intentType, $tenantId);
        if ($knowledgeBaseId !== null) {
            // filtra risultati per KB
            $docIds = array_values(array_unique(array_column($filteredResults, 'document_id')));
            $rows = DB::table('documents')->select(['id','knowledge_base_id'])->whereIn('id', $docIds)->get();
            $docToKb = [];
            foreach ($rows as $r) { $docToKb[(int)$r->id] = (int) ($r->knowledge_base_id ?? 0); }
            $filteredResults = array_values(array_filter($filteredResults, fn($r) => ($docToKb[(int)$r['document_id']] ?? 0) === $knowledgeBaseId));
        }
        
        $debugInfo['semantic_fallback']['filtered_results_count'] = count($filteredResults);
        $debugInfo['semantic_fallback']['sample_filtered_results'] = array_slice($filteredResults, 0, 3);
        
        if (empty($filteredResults)) {
            $debugInfo['semantic_fallback']['failure_reason'] = 'No valid data extracted from semantic results';
            return ['citations' => [], 'confidence' => 0.0, 'debug' => $debug ? $debugInfo : null];
        }
        
        $cits = [];
        $debugInfo['semantic_fallback']['citation_debug'] = [];
        
        foreach (array_slice($filteredResults, 0, 5) as $i => $result) {
            $debugInfo['semantic_fallback']['citation_debug'][$i] = [
                'result_structure' => array_keys($result),
                'document_id' => $result['document_id'] ?? 'missing',
                'intent_field' => $result[$intentType] ?? 'missing',
            ];
            
            // Prima prova con il tenant specifico
            $doc = DB::selectOne('SELECT id, title, path FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$result['document_id'], $tenantId]);
            
            // Se non trovato, prova senza filtro tenant (ma poi skippiamo se tenant diverso)
            if (!$doc) {
                $docAnyTenant = DB::selectOne('SELECT id, title, path, tenant_id FROM documents WHERE id = ? LIMIT 1', [$result['document_id']]);
                if ($docAnyTenant) {
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['db_query_result'] = "found_tenant_{$docAnyTenant->tenant_id}";
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['skip_reason'] = 'wrong_tenant';
                } else {
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['db_query_result'] = 'not_found_anywhere';
                    $debugInfo['semantic_fallback']['citation_debug'][$i]['skip_reason'] = 'document_deleted';
                }
                continue;
            }
            
            $debugInfo['semantic_fallback']['citation_debug'][$i]['db_query_result'] = 'found_correct_tenant';
            
            $citation = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $result['excerpt'],
                'score' => (float) $result['score'],
            ];
            
            // Aggiungi il campo specifico dell'intent
            $citation[$intentType] = $result[$intentType];
            $cits[] = $citation;
            
            $debugInfo['semantic_fallback']['citation_debug'][$i]['citation_created'] = true;
        }
        
        $debugInfo['semantic_fallback']['success'] = true;
        $debugInfo['semantic_fallback']['final_citations_count'] = count($cits);
        
        // Se non abbiamo citazioni, prova fallback con ricerca testuale sui documenti esistenti
        if (empty($cits)) {
            $debugInfo['semantic_fallback']['attempting_text_fallback'] = true;
            $textFallbackResults = $this->attemptTextFallback($intentType, $tenantId, $name, $originalQuery, $knowledgeBaseId);
            if (!empty($textFallbackResults)) {
                $debugInfo['semantic_fallback']['text_fallback_success'] = true;
                $debugInfo['semantic_fallback']['text_fallback_count'] = count($textFallbackResults);
                $cits = $textFallbackResults;
            }
        }
        
        $result = [
            'citations' => $cits,
            'confidence' => 0.8, // Leggermente più bassa per fallback semantico
            'debug' => $debug ? ($debugInfo ?? []) : null,
        ];
        
        return $result;
    }
    
    /**
     * Costruisce query semantica combinando termine originale + sinonimi + contesto intent
     */
    private function buildSemanticQuery(string $name, string $intentType, ?int $tenantId = null): string
    {
        $expandedName = $this->expandNameWithSynonyms($name, $tenantId);
        
        // Aggiungi contesto dell'intent per migliorare la ricerca semantica
        $intentContext = match($intentType) {
            'schedule' => 'orario apertura chiusura ore',
            'phone' => 'telefono numero contatto',
            'email' => 'email posta elettronica contatto',
            'address' => 'indirizzo sede ubicazione dove si trova',
            default => ''
        };
        
        return trim($expandedName . ' ' . $intentContext);
    }
    
    /**
     * Estrae dati specifici dell'intent dai risultati semantici
     */
    private function extractIntentDataFromSemanticResults(array $semanticHits, string $intentType, int $tenantId): array
    {
        $results = [];
        
        foreach ($semanticHits as $hit) {
            $content = $this->text->getChunkSnippet((int)$hit['document_id'], (int)$hit['chunk_index'], 800);
            if (!$content) continue;
            
            // Usa TextSearchService per estrarre dati specifici dal contenuto
            $extracted = match($intentType) {
                'schedule' => $this->extractScheduleFromContent($content),
                'phone' => $this->extractPhoneFromContent($content),
                'email' => $this->extractEmailFromContent($content),
                'address' => $this->extractAddressFromContent($content),
                default => []
            };
            
            // Solo se trovati dati del tipo richiesto
            if (!empty($extracted)) {
                foreach ($extracted as $item) {
                    $results[] = [
                        'document_id' => (int) $hit['document_id'],
                        'chunk_index' => (int) $hit['chunk_index'],
                        $intentType => $item,
                        'score' => (float) $hit['score'],
                        'excerpt' => mb_substr($content, 0, 300) . (mb_strlen($content) > 300 ? '...' : ''),
                    ];
                }
            }
        }
        
        // Ordina per score e deduplica
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_unique($results, SORT_REGULAR);
    }
    
    /**
     * Estrae orari da un contenuto usando i pattern migliorati
     */
    private function extractScheduleFromContent(string $content): array
    {
        // Usa gli stessi pattern del TextSearchService ma applicati direttamente
        $patterns = [
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\b/iu',
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s+(?:alle?\s+)(\d{1,2}[:\.]?\d{2})\b/iu',
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2})\s*[-–—]\s*(\d{1,2})\b(?!\d)/iu',
            '/\b(?:ore|orario|apertura|chiusura|dalle?|fino)\s+(\d{1,2}[:\.]?\d{2})\b/iu',
            '/\b(lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica|lun|mar|mer|gio|ven|sab|dom)\s*:?\s*(\d{1,2}(?:[:\.]?\d{2})?(?:\s*[-–—]\s*\d{1,2}(?:[:\.]?\d{2})?)?)\b/iu',
        ];
        
        $schedules = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $match) {
                    $schedule = $match[0];
                    $offset = $match[1];
                    
                    // Usa la stessa validazione del TextSearchService
                    $reflection = new \ReflectionClass($this->text);
                    $isValidMethod = $reflection->getMethod('isValidSchedule');
                    $isValidMethod->setAccessible(true);
                    
                    if ($isValidMethod->invoke($this->text, $schedule, $content, $offset)) {
                        $schedules[] = trim($schedule);
                    }
                }
            }
        }
        
        return array_unique($schedules);
    }
    
    /**
     * Estrae telefoni da un contenuto
     */
    private function extractPhoneFromContent(string $content): array
    {
        $patterns = [
            '/(?<!\d)(?:\+39\s*)?0\d{1,3}\s*\d{6,8}(?!\d)/u',
            '/(?<!\d)(?:\+39\s*)?3\d{2}\s*\d{3}\s*\d{3,4}(?!\d)/u',
            '/(?<!\d)(?:800|199|892|899|163|166)\s*\d{3,6}(?!\d)/u',
            '/(?<!\d)(?:112|113|115|117|118|1515|1530|1533|114)(?!\d)/u',
        ];
        
        $phones = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $phone) {
                    // Usa la validazione del TextSearchService
                    $reflection = new \ReflectionClass($this->text);
                    $isValidMethod = $reflection->getMethod('isLikelyNotPhone');
                    $isValidMethod->setAccessible(true);
                    
                    if (!$isValidMethod->invoke($this->text, $phone, $content, $phone)) {
                        $phones[] = trim($phone);
                    }
                }
            }
        }
        
        return array_unique($phones);
    }
    
    /**
     * Estrae email da un contenuto
     */
    private function extractEmailFromContent(string $content): array
    {
        $pattern = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu';
        preg_match_all($pattern, $content, $matches);
        return array_unique($matches[0] ?? []);
    }
    
    /**
     * Estrae indirizzi da un contenuto
     */
    private function extractAddressFromContent(string $content): array
    {
        $types = '(?:via|viale|piazza|p\.?zza|corso|largo|vicolo|piazzale|strada|str\.)';
        $civic = '(?:\d{1,4}[A-Za-z]?)';
        $cap = '(?:\b\d{5}\b)';
        $pattern = '/\b'.$types.'\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]{2,60}(?:,?\s+'.$civic.')?(?:.*?'.$cap.')?/iu';
        
        preg_match_all($pattern, $content, $matches);
        return array_unique($matches[0] ?? []);
    }

    /**
     * Fallback testuale quando Milvus non ha dati validi per il tenant
     */
    private function attemptTextFallback(string $intentType, int $tenantId, string $name, string $originalQuery, ?int $knowledgeBaseId = null): array
    {
        // Usa la ricerca testuale diretta come ultima risorsa
        $expandedName = $this->expandNameWithSynonyms($name, $tenantId);
        
        $results = match($intentType) {
            'schedule' => $this->text->findSchedulesNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            'phone' => $this->text->findPhonesNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            'email' => $this->text->findEmailsNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            'address' => $this->text->findAddressesNearName($tenantId, $expandedName, 5, $knowledgeBaseId),
            default => []
        };
        
        if (empty($results)) {
            return [];
        }
        
        $cits = [];
        foreach ($results as $r) {
            $snippet = (string) ($r['excerpt'] ?? '') ?: ($this->text->getChunkSnippet((int)$r['document_id'], (int)$r['chunk_index'], 400) ?? '');
            $doc = DB::selectOne('SELECT id, title, path FROM documents WHERE id = ? AND tenant_id = ? LIMIT 1', [$r['document_id'], $tenantId]);
            if (!$doc) { continue; }
            
            $citation = [
                'id' => (int) $doc->id,
                'title' => (string) $doc->title,
                'url' => url('storage/'.$doc->path),
                'snippet' => $snippet,
                'score' => (float) $r['score'],
            ];
            
            // Aggiungi il campo specifico dell'intent
            $citation[$intentType] = (string) $r[$intentType];
            $cits[] = $citation;
        }
        
        return $cits;
    }

    /**
     * Esegue l'intent di ringraziamento restituendo una risposta cortese diretta
     */
    private function executeThanksIntent(int $tenantId, string $query, bool $debug): array
    {
        // Ottieni informazioni del tenant per personalizzare la risposta
        $tenant = Tenant::find($tenantId);
        $tenantName = $tenant ? $tenant->name : '';
        
        // Array di risposte cortesi in base alla lingua del tenant
        $languages = $this->getTenantLanguages($tenantId);
        $responses = $this->getThanksResponses($languages, $tenantName);
        
        // Seleziona una risposta casuale per varietà
        $response = $responses[array_rand($responses)];
        
        // Crea una citazione fittizia per mantenere la struttura standard
        $citation = [
            'id' => 0,
            'title' => 'Assistente Virtuale',
            'url' => '#',
            'snippet' => $response,
            'score' => 1.0,
            'knowledge_base' => null,
        ];
        
        $debugInfo = $debug ? [
            'thanks_intent' => [
                'detected_languages' => $languages,
                'tenant_name' => $tenantName,
                'available_responses' => count($responses),
                'selected_response' => $response,
            ]
        ] : null;
        
        return [
            'citations' => [$citation],
            'confidence' => 1.0,
            'debug' => $debugInfo,
        ];
    }
    
    /**
     * Restituisce risposte di cortesia appropriate in base alla lingua
     */
    private function getThanksResponses(array $languages, string $tenantName = ''): array
    {
        $responses = [];
        
        // Risposte in italiano (sempre incluse come fallback)
        $responses = array_merge($responses, [
            'Prego, sono felice di averti aiutato!',
            'Di niente, è stato un piacere assisterti.',
            'Figurati! Se hai altre domande, sono qui per aiutarti.',
            'Prego! Spero che le informazioni siano state utili.',
            'È stato un piacere aiutarti. Buona giornata!',
            'Di niente! Non esitare a contattarmi se hai altre domande.',
            'Sono contento di essere stato utile!',
        ]);
        
        // Personalizza con nome tenant se disponibile
        if ($tenantName !== '') {
            $responses[] = "Prego! Sono qui per aiutarti con i servizi di {$tenantName}.";
            $responses[] = "Di niente! Per altre informazioni su {$tenantName}, sono sempre disponibile.";
        }
        
        // Aggiungi risposte in altre lingue se supportate
        if (in_array('en', $languages)) {
            $responses = array_merge($responses, [
                'You\'re welcome! I\'m glad I could help.',
                'No problem! Feel free to ask if you have more questions.',
                'My pleasure! I hope the information was useful.',
                'You\'re welcome! Have a great day!',
            ]);
        }
        
        if (in_array('es', $languages)) {
            $responses = array_merge($responses, [
                '¡De nada! Me alegra haber podido ayudarte.',
                '¡No hay problema! Si tienes más preguntas, estoy aquí.',
                '¡Un placer! Espero que la información haya sido útil.',
            ]);
        }
        
        if (in_array('fr', $languages)) {
            $responses = array_merge($responses, [
                'De rien! Je suis content d\'avoir pu vous aider.',
                'Pas de problème! N\'hésitez pas si vous avez d\'autres questions.',
                'Je vous en prie! J\'espère que les informations ont été utiles.',
            ]);
        }
        
        return $responses;
    }
    
    /**
     * Ottieni intent abilitati per il tenant
     */
    private function getTenantEnabledIntents(?int $tenantId): array
    {
        if ($tenantId === null) {
            return []; // Tutti abilitati di default se nessun tenant
        }
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant || empty($tenant->intents_enabled)) {
            return []; // Tutti abilitati di default se configurazione vuota
        }
        
        return (array) $tenant->intents_enabled;
    }
    
    /**
     * Ottieni keyword extra per il tenant
     */
    private function getTenantExtraKeywords(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [];
        }
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant || empty($tenant->extra_intent_keywords)) {
            return [];
        }
        
        return (array) $tenant->extra_intent_keywords;
    }
    
    /**
     * Ottieni keywords per un intent specifico
     */
    private function getIntentKeywords(string $intentType, array $languages): array
    {
        return match($intentType) {
            'thanks' => $this->keywordsThanks($languages),
            'schedule' => $this->keywordsSchedule($languages),
            'address' => $this->keywordsAddress($languages),
            'email' => $this->keywordsEmail($languages),
            'phone' => $this->keywordsPhone($languages),
            default => []
        };
    }
    
    /**
     * Ottieni sinonimi personalizzati del tenant con fallback ai sinonimi globali
     */
    private function getTenantSynonyms(?int $tenantId): array
    {
        if ($tenantId === null) {
            return $this->getSynonymsMap(); // Fallback a sinonimi globali
        }
        
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return $this->getSynonymsMap(); // Fallback se tenant non trovato
        }
        
        // Se il tenant ha sinonimi personalizzati, usali; altrimenti fallback
        if (!empty($tenant->custom_synonyms)) {
            return (array) $tenant->custom_synonyms;
        }
        
        return $this->getSynonymsMap(); // Fallback a sinonimi globali
    }

    /**
     * Mappa centralizzata dei sinonimi (fallback globale)
     */
    private function getSynonymsMap(): array
    {
        return [
            // Sinonimi per forze dell'ordine e servizi pubblici
            'vigili urbani' => 'polizia locale municipale vigili',
            'polizia locale' => 'vigili urbani municipale',
            'polizia municipale' => 'vigili urbani polizia locale',
            'vigili' => 'polizia locale vigili urbani',
            'municipio' => 'comune ufficio municipale',
            'comune' => 'municipio ufficio comunale',
            'anagrafe' => 'ufficio anagrafico comune municipio',
            'ufficio tecnico' => 'comune municipio tecnico',
            // Sinonimi per servizi sanitari
            'pronto soccorso' => 'ospedale emergenza 118',
            'ospedale' => 'pronto soccorso sanitario',
            'asl' => 'azienda sanitaria ospedale',
            // Sinonimi per servizi postali
            'poste' => 'ufficio postale poste italiane',
            'ufficio postale' => 'poste poste italiane',
        ];
    }
}


