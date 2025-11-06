<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TextSearchService
{
    /**
     * Ricerca full-text per tenant su Postgres FTS.
     * @return array<int, array{document_id:int, chunk_index:int, score:float}>
     */
    public function searchTopK(int $tenantId, string $query, int $k = 50, ?int $knowledgeBaseId = null): array
    {
        if ($query === '') {
            return [];
        }

        // FIX: Use OR logic instead of AND logic for better synonym expansion support
        // Convert query to OR-separated terms: "tel phone comando" -> "tel | phone | comando"
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $orQuery = implode(' | ', $terms);

        if ($knowledgeBaseId !== null) {
            $sql = <<<SQL
                WITH q AS (SELECT to_tsquery('simple', :q) AS tsq)
                SELECT dc.document_id, dc.chunk_index,
                       ts_rank(to_tsvector('simple', dc.content), q.tsq) AS score
                FROM document_chunks dc
                INNER JOIN documents d ON d.id = dc.document_id
                , q
                WHERE dc.tenant_id = :tenant
                  AND d.tenant_id = :tenant
                  AND d.knowledge_base_id = :kbId
                  AND to_tsvector('simple', dc.content) @@ q.tsq
                ORDER BY score DESC
                LIMIT :k
            SQL;

            $rows = DB::select($sql, [
                'q' => $orQuery,
                'tenant' => $tenantId,
                'k' => $k,
                'kbId' => (int) $knowledgeBaseId,
            ]);
        } else {
            $sql = <<<SQL
                WITH q AS (SELECT to_tsquery('simple', :q) AS tsq)
                SELECT dc.document_id, dc.chunk_index,
                       ts_rank(to_tsvector('simple', dc.content), q.tsq) AS score
                FROM document_chunks dc
                INNER JOIN documents d ON d.id = dc.document_id
                , q
                WHERE dc.tenant_id = :tenant
                  AND d.tenant_id = :tenant
                  AND to_tsvector('simple', dc.content) @@ q.tsq
                ORDER BY score DESC
                LIMIT :k
            SQL;

            $rows = DB::select($sql, [
                'q' => $orQuery,
                'tenant' => $tenantId,
                'k' => $k,
            ]);
        }

        return array_map(static function ($r) {
            return [
                'document_id' => (int) $r->document_id,
                'chunk_index' => (int) $r->chunk_index,
                'score' => (float) $r->score,
            ];
        }, $rows);
    }

    public function getChunkSnippet(int $documentId, int $chunkIndex, int $max = 300): ?string
    {
        $row = DB::selectOne('SELECT content FROM document_chunks WHERE document_id = ? AND chunk_index = ? LIMIT 1', [$documentId, $chunkIndex]);
        if ($row === null) {
            return null;
        }
        $s = (string) $row->content;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }

    /**
     * Trova numeri di telefono vicini a un nome (intenti lookup "telefono").
     * Ritorna una lista di risultati con phone, document_id, chunk_index, score.
     * Score combina similarità trigram del nome e vicinanza nome↔numero.
     * @return array<int, array{phone:string,document_id:int,chunk_index:int,score:float}>
     */
    public function findPhonesNearName(int $tenantId, string $name, int $limit = 10, ?int $knowledgeBaseId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $nameLower = mb_strtolower($name);
        // 🔧 FIX: Tokenizza TUTTI i termini (non solo primi 2) per supportare sinonimi espansi
        // Es: "vigili urbani polizia locale municipale" -> tutti i termini vengono considerati
        $parts = preg_split('/\s+/', $nameLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';
        
        // Costruisci condizioni SQL OR per matchare qualsiasi combinazione di termini sinonimi
        $synonymConditions = $this->buildSynonymMatchingConditions($parts, $knowledgeBaseId !== null);

        // Candidati via trigram/ILIKE, limit ampio per post-filter
        if ($knowledgeBaseId !== null) {
            $rows = DB::select(
                "SELECT dc.document_id, dc.chunk_index, dc.content
                 FROM document_chunks dc
                 INNER JOIN documents d ON d.id = dc.document_id
                 WHERE dc.tenant_id = :t AND d.tenant_id = :t
                   AND d.knowledge_base_id = :kb
                   AND (
                        (LOWER(dc.content) ILIKE :phrase1)
                     OR (LOWER(dc.content) ILIKE :phrase2)
                     OR (LOWER(dc.content) ILIKE :first AND LOWER(dc.content) ILIKE :last)
                     {$synonymConditions['sql']}
                     OR similarity(LOWER(dc.content), :name) > 0.2
                   )
                 ORDER BY similarity(LOWER(dc.content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'kb' => (int) $knowledgeBaseId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        } else {
            $rows = DB::select(
                "SELECT document_id, chunk_index, content
                 FROM document_chunks
                 WHERE tenant_id = :t
                   AND (
                        (LOWER(content) ILIKE :phrase1)
                     OR (LOWER(content) ILIKE :phrase2)
                     OR (LOWER(content) ILIKE :first AND LOWER(content) ILIKE :last)
                     {$synonymConditions['sql']}
                     OR similarity(LOWER(content), :name) > 0.2
                   )
                 ORDER BY similarity(LOWER(content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        }

        // Pattern più specifici per numeri di telefono italiani
        $sep = '[\\s\\.\\-\\x{00A0}\\x{2009}\\x{202F}\\x{2028}\\x{2029}\\x{2010}-\\x{2015}]*';
        $patterns = [
            // Fissi: +39 0XX XXXXXXX oppure 0XX XXXXXXX (word boundary all'inizio)
            '/(?<!\d)(?:\+39'.$sep.')?0\d{1,3}'.$sep.'\d{6,8}(?!\d)/u',
            // Mobili: +39 3XX XXXXXXX oppure 3XX XXXXXXX  
            '/(?<!\d)(?:\+39'.$sep.')?3\d{2}'.$sep.'\d{3}'.$sep.'\d{3,4}(?!\d)/u',
            // Numeri verdi e servizi speciali
            '/(?<!\d)(?:800|199|892|899|163|166)'.$sep.'\d{3,6}(?!\d)/u',
            // Emergenze con contesto
            '/(?<!\d)(?:112|113|115|117|118|1515|1530|1533|114)(?!\d)/u',
        ];
        $out = [];
        foreach ($rows as $r) {
            $content = (string) $r->content;
            // normalizza per similarità
            $lower = mb_strtolower($content);
            // distanza approssimata nome-numero: min distanza in caratteri
            // 🔧 FIX: Cerca posizione nome considerando TUTTI i termini sinonimi
            $namePos = mb_strpos($lower, $nameLower);
            if ($namePos === false) {
                // Prova a matchare qualsiasi coppia di termini sinonimi
                foreach ($parts as $i => $term1) {
                    foreach (array_slice($parts, $i + 1) as $term2) {
                        $p1 = mb_strpos($lower, $term1);
                        $p2 = mb_strpos($lower, $term2);
                        if ($p1 !== false && $p2 !== false) {
                            $namePos = (int) floor(($p1 + $p2) / 2);
                            break 2; // Exit both loops
                        }
                    }
                }
            }
            $phones = [];
            // Cerca con tutti i pattern
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $m);
                $phones = array_merge($phones, $m[0] ?? []);
            }
            // Rimuovi duplicati e normalizza
            $phones = array_unique($phones);
            
            foreach ($phones as $phRaw) {
                $ph = trim(preg_replace('/[\s\.\-\(\)\x{00A0}\x{2009}\x{202F}\x{2028}\x{2029}\x{2010}-\x{2015}]/u', '', $phRaw));
                if ($ph === '') continue;
                
                // Filtri per escludere falsi positivi
                if ($this->isLikelyNotPhone($ph, $content, $phRaw)) continue;
                $pos = mb_strpos($content, $phRaw);
                $dist = $namePos !== false && $pos !== false ? abs($pos - $namePos) : 9999;
                // score: simil(name, chunk) + bonus vicinanza inversa
                $sim = $this->trigramSimilarity($lower, $name);
                $score = $sim + max(0.0, 1.0 - min($dist, 500) / 500.0);
                // excerpt centrato sul numero
                $excerpt = $this->excerptAround($content, (int) ($pos !== false ? $pos : 0), 220);
                $out[] = [
                    'phone' => $ph,
                    'document_id' => (int) $r->document_id,
                    'chunk_index' => (int) $r->chunk_index,
                    'score' => (float) $score,
                    'excerpt' => $excerpt,
                ];
            }
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    private function trigramSimilarity(string $a, string $b): float
    {
        // very approximate: Jaccard over trigrams
        $ngr = function(string $s){ $n=[]; $len=mb_strlen($s); for($i=0;$i<$len-2;$i++){ $n[mb_substr($s,$i,3)] = true; } return array_keys($n); };
        $A = $ngr($a); $B = $ngr($b);
        if ($A===[] || $B===[]) return 0.0;
        $setA = array_fill_keys($A, true);
        $inter=0; foreach($B as $g){ if(isset($setA[$g])) $inter++; }
        $uni = count($A) + count($B) - $inter;
        return $uni>0 ? $inter/$uni : 0.0;
    }

    private function excerptAround(string $text, int $center, int $size): string
    {
        $len = mb_strlen($text);
        if ($len <= $size) return $text;
        $start = max(0, $center - intdiv($size, 2));
        if ($start + $size > $len) { $start = max(0, $len - $size); }
        $slice = mb_substr($text, $start, $size);
        return ($start>0 ? '…' : '').$slice.($start+$size<$len ? '…' : '');
    }

    /**
     * Trova email vicine a un nome.
     * @return array<int, array{email:string,document_id:int,chunk_index:int,score:float,excerpt:string}>
     */
    public function findEmailsNearName(int $tenantId, string $name, int $limit = 10, ?int $knowledgeBaseId = null): array
    {
        $name = trim($name);
        if ($name === '') return [];
        $nameLower = mb_strtolower($name);
        // 🔧 FIX: Tokenizza TUTTI i termini per supportare sinonimi espansi
        $parts = preg_split('/\s+/', $nameLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';
        
        // Costruisci condizioni SQL OR per matchare qualsiasi combinazione di termini sinonimi
        $synonymConditions = $this->buildSynonymMatchingConditions($parts, $knowledgeBaseId !== null);

        if ($knowledgeBaseId !== null) {
            $rows = DB::select(
                "SELECT dc.document_id, dc.chunk_index, dc.content FROM document_chunks dc
                 INNER JOIN documents d ON d.id = dc.document_id
                 WHERE dc.tenant_id = :t AND d.tenant_id = :t
                   AND d.knowledge_base_id = :kb
                   AND (
                       (LOWER(dc.content) ILIKE :phrase1)
                    OR (LOWER(dc.content) ILIKE :phrase2)
                    OR (LOWER(dc.content) ILIKE :first AND LOWER(dc.content) ILIKE :last)
                    {$synonymConditions['sql']}
                    OR similarity(LOWER(dc.content), :name) > 0.2
                   )
                 ORDER BY similarity(LOWER(dc.content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'kb' => (int) $knowledgeBaseId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        } else {
            $rows = DB::select(
                "SELECT document_id, chunk_index, content FROM document_chunks
                 WHERE tenant_id = :t AND (
                     (LOWER(content) ILIKE :phrase1)
                  OR (LOWER(content) ILIKE :phrase2)
                  OR (LOWER(content) ILIKE :first AND LOWER(content) ILIKE :last)
                  {$synonymConditions['sql']}
                  OR similarity(LOWER(content), :name) > 0.2
                 )
                 ORDER BY similarity(LOWER(content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        }

        $pattern = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu';
        $out = [];
        $primaryParts = [];
        foreach ($rows as $r) {
            $content = (string) $r->content;
            $lower = mb_strtolower($content);
            $requiredTerms = $primaryParts !== [] ? $primaryParts : $parts;
            $hasRequiredTerm = false;
            foreach ($requiredTerms as $term) {
                $term = trim($term);
                if ($term === '' || mb_strlen($term) < 3) {
                    continue;
                }
                if (mb_strpos($lower, $term) !== false) {
                    $hasRequiredTerm = true;
                    break;
                }
            }
            if ($requiredTerms !== [] && !$hasRequiredTerm) {
                continue;
            }
            // 🔧 FIX: Cerca posizione nome considerando TUTTI i termini sinonimi
            $namePos = mb_strpos($lower, $nameLower);
            if ($namePos === false) {
                // Prova a matchare qualsiasi coppia di termini sinonimi
                foreach ($parts as $i => $term1) {
                    foreach (array_slice($parts, $i + 1) as $term2) {
                        $p1 = mb_strpos($lower, $term1);
                        $p2 = mb_strpos($lower, $term2);
                        if ($p1 !== false && $p2 !== false) {
                            $namePos = (int) floor(($p1 + $p2) / 2);
                            break 2;
                        }
                    }
                }
            }
            preg_match_all($pattern, $content, $m);
            $emails = $m[0] ?? [];
            foreach ($emails as $em) {
                $pos = mb_strpos($content, $em);
                $dist = $namePos !== false && $pos !== false ? abs($pos - $namePos) : 9999;
                $sim = $this->trigramSimilarity($lower, $nameLower);
                $score = $sim + max(0.0, 1.0 - min($dist, 500) / 500.0);
                $excerpt = $this->excerptAround($content, (int) ($pos !== false ? $pos : 0), 220);
                $out[] = [
                    'email' => (string) $em,
                    'document_id' => (int) $r->document_id,
                    'chunk_index' => (int) $r->chunk_index,
                    'score' => (float) $score,
                    'excerpt' => $excerpt,
                ];
            }
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Trova indirizzi (via/viale/piazza/corso/largo/vicolo/strada) vicino a un nome.
     * @return array<int, array{address:string,document_id:int,chunk_index:int,score:float,excerpt:string}>
     */
    public function findAddressesNearName(int $tenantId, string $name, int $limit = 10, ?int $knowledgeBaseId = null, ?string $originalName = null): array
    {
        $name = trim($name);
        if ($name === '') return [];
        $nameLower = mb_strtolower($name);
        // 🔧 FIX: Tokenizza TUTTI i termini per supportare sinonimi espansi
        $parts = preg_split('/\s+/', $nameLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';
        $primaryParts = [];
        if ($originalName !== null) {
            $primaryParts = preg_split('/\s+/', mb_strtolower(trim($originalName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $genericTerms = ['ufficio', 'uffici', 'sportello', 'sportelli', 'servizio', 'servizi', 'municipio', 'comune', 'ente', 'office', 'service', 'ordinanze'];
        
        // Costruisci condizioni SQL OR per matchare qualsiasi combinazione di termini sinonimi
        $synonymConditions = $this->buildSynonymMatchingConditions($parts, $knowledgeBaseId !== null);

        if ($knowledgeBaseId !== null) {
            $rows = DB::select(
                "SELECT dc.document_id, dc.chunk_index, dc.content FROM document_chunks dc
                 INNER JOIN documents d ON d.id = dc.document_id
                 WHERE dc.tenant_id = :t AND d.tenant_id = :t
                   AND d.knowledge_base_id = :kb
                   AND (
                       (LOWER(dc.content) ILIKE :phrase1)
                    OR (LOWER(dc.content) ILIKE :phrase2)
                    OR (LOWER(dc.content) ILIKE :first AND LOWER(dc.content) ILIKE :last)
                    {$synonymConditions['sql']}
                    OR similarity(LOWER(dc.content), :name) > 0.2
                   )
                 ORDER BY similarity(LOWER(dc.content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'kb' => (int) $knowledgeBaseId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        } else {
            $rows = DB::select(
                "SELECT document_id, chunk_index, content FROM document_chunks
                 WHERE tenant_id = :t AND (
                     (LOWER(content) ILIKE :phrase1)
                  OR (LOWER(content) ILIKE :phrase2)
                  OR (LOWER(content) ILIKE :first AND LOWER(content) ILIKE :last)
                  {$synonymConditions['sql']}
                  OR similarity(LOWER(content), :name) > 0.2
                 )
                 ORDER BY similarity(LOWER(content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        }

        // Pattern base indirizzi italiani
        $types = '(?:via|viale|piazza|p\.?zza|corso|largo|vicolo|piazzale|strada|str\.)';
        $civic = '(?:\d{1,4}[A-Za-z]?)';
        $cap   = '(?:\b\d{5}\b)';
        $addrPattern = '/\b'.$types.'\s+[A-Za-zÀ-ÖØ-öø-ÿ\'\-\s]{2,60}(?:,?\s+'.$civic.')?(?:.*?'.$cap.')?/iu';

        $out = [];
        foreach ($rows as $r) {
            $content = (string) $r->content;
            $lower = mb_strtolower($content);
            $requiredTerms = $primaryParts !== [] ? $primaryParts : $parts;
            $hasRequiredTerm = false;
            foreach ($requiredTerms as $term) {
                $term = trim($term);
                if ($term === '' || mb_strlen($term) < 3) {
                    continue;
                }
                if (in_array($term, $genericTerms, true)) {
                    continue;
                }
                if (mb_strpos($lower, $term) !== false) {
                    $hasRequiredTerm = true;
                    break;
                }
            }
            if ($requiredTerms !== [] && ! $hasRequiredTerm) {
                continue;
            }

            // 🔧 FIX: Cerca posizione nome considerando TUTTI i termini sinonimi
            $namePos = mb_strpos($lower, $nameLower);
            if ($namePos === false) {
                foreach ($parts as $i => $term1) {
                    if (in_array($term1, $genericTerms, true)) {
                        continue;
                    }
                    foreach (array_slice($parts, $i + 1) as $term2) {
                        if (in_array($term2, $genericTerms, true)) {
                            continue;
                        }
                        $p1 = mb_strpos($lower, $term1);
                        $p2 = mb_strpos($lower, $term2);
                        if ($p1 !== false && $p2 !== false) {
                            $namePos = (int) floor(($p1 + $p2) / 2);
                            break 2;
                        }
                    }
                }
            }
            if ($parts !== [] && $namePos === false) {
                foreach ($parts as $term) {
                    if (in_array($term, $genericTerms, true)) {
                        continue;
                    }
                    $p = mb_strpos($lower, $term);
                    if ($p !== false) {
                        $namePos = $p;
                        break;
                    }
                }
            }

            $addressCandidates = [];

            preg_match_all($addrPattern, $content, $m, PREG_OFFSET_CAPTURE);
            if (! empty($m[0])) {
                foreach ($m[0] as $match) {
                    $value = trim($match[0]);
                    $position = $match[1];
                    $addressCandidates[] = [
                        'value' => $value,
                        'position' => $position,
                    ];
                }
            }

            foreach ($this->extractAddressesFromTable($content) as $tableAddress) {
                $addressCandidates[] = $tableAddress;
            }

            if ($addressCandidates === []) {
                continue;
            }

            $unique = [];
            $seen = [];
            foreach ($addressCandidates as $candidate) {
                $value = preg_replace('/\s+/', ' ', trim($candidate['value']));
                if ($value === '') {
                    continue;
                }
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $candidate['value'] = $value;
                $unique[] = $candidate;
            }

            foreach ($unique as $candidate) {
                $pos = $candidate['position'] ?? mb_strpos($content, $candidate['value']);
                if ($pos === false) {
                    continue;
                }

                $dist = $namePos !== false ? abs($pos - $namePos) : 9999;
                if ($parts !== [] && ($namePos === false || $dist > 450)) {
                    continue;
                }

                $contextSegment = mb_substr($lower, max(0, $pos - 200), 400);
                $hasContextTerm = $requiredTerms === [];
                if (! $hasContextTerm) {
                    foreach ($requiredTerms as $term) {
                        $term = trim($term);
                        if ($term === '' || mb_strlen($term) < 3) {
                            continue;
                        }
                        if (in_array($term, $genericTerms, true)) {
                            continue;
                        }
                        if (mb_strpos($contextSegment, $term) !== false) {
                            $hasContextTerm = true;
                            break;
                        }
                    }
                }

                if (! $hasContextTerm) {
                    continue;
                }

                $sim = $this->trigramSimilarity($lower, $nameLower);
                $score = $sim + max(0.0, 1.0 - min($dist, 450) / 450.0);
                $excerpt = $this->excerptAround($content, (int) $pos, 280);
                $out[] = [
                    'address' => $candidate['value'],
                    'document_id' => (int) $r->document_id,
                    'chunk_index' => (int) $r->chunk_index,
                    'score' => (float) $score,
                    'excerpt' => $excerpt,
                ];
            }
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /**
     * Trova orari/schedule vicini a un nome.
     * @return array<int, array{schedule:string,document_id:int,chunk_index:int,score:float,excerpt:string}>
     */
    public function findSchedulesNearName(int $tenantId, string $name, int $limit = 10, ?int $knowledgeBaseId = null, ?string $originalName = null): array
    {
        $name = trim($name);
        if ($name === '') return [];
        $nameLower = mb_strtolower($name);
        // 🔧 FIX: Tokenizza TUTTI i termini per supportare sinonimi espansi
        $parts = preg_split('/\s+/', $nameLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? '';
        $last  = $parts[1] ?? '';
        $primaryParts = [];
        if ($originalName !== null) {
            $primaryParts = preg_split('/\s+/', mb_strtolower(trim($originalName)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $genericTerms = ['ufficio', 'uffici', 'sportello', 'sportelli', 'servizio', 'servizi', 'office', 'service'];
        
        // Costruisci condizioni SQL OR per matchare qualsiasi combinazione di termini sinonimi
        $synonymConditions = $this->buildSynonymMatchingConditions($parts, $knowledgeBaseId !== null);

        if ($knowledgeBaseId !== null) {
            $rows = DB::select(
                "SELECT dc.document_id, dc.chunk_index, dc.content FROM document_chunks dc
                 INNER JOIN documents d ON d.id = dc.document_id
                 WHERE dc.tenant_id = :t AND d.tenant_id = :t
                   AND d.knowledge_base_id = :kb
                   AND (
                       (LOWER(dc.content) ILIKE :phrase1)
                    OR (LOWER(dc.content) ILIKE :phrase2)
                    OR (LOWER(dc.content) ILIKE :first AND LOWER(dc.content) ILIKE :last)
                    {$synonymConditions['sql']}
                    OR similarity(LOWER(dc.content), :name) > 0.2
                   )
                 ORDER BY similarity(LOWER(dc.content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'kb' => (int) $knowledgeBaseId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        } else {
            $rows = DB::select(
                "SELECT document_id, chunk_index, content FROM document_chunks
                 WHERE tenant_id = :t AND (
                     (LOWER(content) ILIKE :phrase1)
                  OR (LOWER(content) ILIKE :phrase2)
                  OR (LOWER(content) ILIKE :first AND LOWER(content) ILIKE :last)
                  {$synonymConditions['sql']}
                  OR similarity(LOWER(content), :name) > 0.2
                 )
                 ORDER BY similarity(LOWER(content), :name) DESC
                 LIMIT 200",
                array_merge([
                    't' => $tenantId,
                    'name' => $nameLower,
                    'phrase1' => '%'.$first.'%'.$last.'%',
                    'phrase2' => '%'.$last.'%'.$first.'%',
                    'first' => '%'.$first.'%',
                    'last'  => '%'.$last.'%',
                ], $synonymConditions['params'])
            );
        }

        // Pattern più precisi per orari italiani
        $patterns = [
            // Orari completi con range (es: 08:30-17:00, 9.00-18.00, dalle 9 alle 18)
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\b/iu',
            // Range con "alle" (es: dalle 9:30 alle 17:00, dal lunedì 8.00 al venerdì 18.00)
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}[:\.]?\d{2})\s+(?:alle?\s+)(\d{1,2}[:\.]?\d{2})\b/iu',
            // Orari solo con ore (es: dalle 9 alle 18, 8-17)
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2})\s*[-–—]\s*(\d{1,2})\b(?!\d)/iu',
            // Range ore con "alle" (es: dalle 9 alle 18)
            '/\b(?:dalle?\s+)?(?:ore\s+)?(\d{1,2})\s+(?:alle?\s+)(\d{1,2})\b(?!\d)/iu',
            // Orari con giorni della settimana
            '/\b(lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica|lun|mar|mer|gio|ven|sab|dom)\s*:?\s*(\d{1,2}(?:[:\.]?\d{2})?(?:\s*[-–—]\s*\d{1,2}(?:[:\.]?\d{2})?)?)\b/iu',
            // Apertura/chiusura con contesto
            '/\b(?:aperto|apertura|chiusura|orario)\s+(?:dalle?\s+)?(?:ore\s+)?(\d{1,2}(?:[:\.]?\d{2})?)\s*(?:[-–—]|alle?\s+)?\s*(\d{1,2}(?:[:\.]?\d{2})?)\b/iu',
            // Orario singolo con contesto esplicito (es: "ore 14:30", "apertura 9:00")
            '/\b(?:ore|orario|apertura|chiusura|dalle?|fino)\s+(\d{1,2}[:\.]?\d{2})\b/iu',
            // Pattern per mattina/pomeriggio (es: "9:00-12:00 e 15:00-18:00")
            '/\b(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\s*(?:e|,)\s*(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})\b/iu',
        ];

        $out = [];
        foreach ($rows as $r) {
            $content = (string) $r->content;
            $lower = mb_strtolower($content);
            $requiredTerms = $primaryParts !== [] ? $primaryParts : $parts;
            $hasRequiredTerm = false;
            foreach ($requiredTerms as $term) {
                $term = trim($term);
                if ($term === '' || mb_strlen($term) < 3) {
                    continue;
                }
                if (in_array($term, $genericTerms, true)) {
                    continue;
                }
                if (mb_strpos($lower, $term) !== false) {
                    $hasRequiredTerm = true;
                    break;
                }
            }
            if ($requiredTerms !== [] && !$hasRequiredTerm) {
                continue;
            }
            // 🔧 FIX: Cerca posizione nome considerando TUTTI i termini sinonimi
            $namePos = mb_strpos($lower, $nameLower);
            if ($namePos === false) {
                // Prova a matchare qualsiasi coppia di termini sinonimi
                foreach ($parts as $i => $term1) {
                    if (in_array($term1, $genericTerms, true)) {
                        continue;
                    }
                    foreach (array_slice($parts, $i + 1) as $term2) {
                        if (in_array($term2, $genericTerms, true)) {
                            continue;
                        }
                        $p1 = mb_strpos($lower, $term1);
                        $p2 = mb_strpos($lower, $term2);
                        if ($p1 !== false && $p2 !== false) {
                            $namePos = (int) floor(($p1 + $p2) / 2);
                            break 2;
                        }
                    }
                }
            }
            if ($parts !== [] && $namePos === false) {
                foreach ($parts as $term) {
                    if (in_array($term, $genericTerms, true)) {
                        continue;
                    }
                    $p = mb_strpos($lower, $term);
                    if ($p !== false) {
                        $namePos = $p;
                        break;
                    }
                }
            }
            if ($parts !== [] && $namePos === false) {
                continue;
            }
            
        $scheduleCandidates = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE);
            if (!empty($m[0])) {
                foreach ($m[0] as $match) {
                    $schedule = $match[0];
                    $offset = $match[1];

                    if ($this->isValidSchedule($schedule, $content, $offset)) {
                        $scheduleCandidates[] = [
                            'value' => trim($schedule),
                            'position' => $offset,
                        ];
                    }
                }
            }
        }

        foreach ($this->extractSchedulesFromTable($content, $primaryParts ?: $parts) as $tableCandidate) {
            $scheduleCandidates[] = $tableCandidate;
        }

        if ($scheduleCandidates !== []) {
            $unique = [];
            $seen = [];
            foreach ($scheduleCandidates as $candidate) {
                $value = trim($candidate['value']);
                if ($value === '') {
                    continue;
                }
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $unique[] = $candidate;
            }
            $scheduleCandidates = $unique;
        }

        if ($scheduleCandidates === []) {
                $appointmentText = $this->extractAppointmentSchedule($content, $primaryParts ?: $parts);
                if ($appointmentText !== null) {
                    $label = $this->extractEntityLabel($content, $primaryParts ?: $parts, $namePos);
                    if ($label === null && ($primaryParts !== [] || $parts !== [])) {
                        continue;
                    }
                    $posAppointment = mb_strpos($content, $appointmentText);
                    $distAppointment = $namePos !== false && $posAppointment !== false ? abs($posAppointment - $namePos) : 250;
                    $simAppointment = $this->trigramSimilarity($lower, $nameLower);
                    $scoreAppointment = $simAppointment + max(0.0, 1.0 - min($distAppointment, 350) / 350.0);
                    $excerptAppointment = $this->excerptAround($content, (int) ($posAppointment !== false ? $posAppointment : 0), 300);
                    $out[] = [
                        'schedule' => trim($appointmentText),
                        'document_id' => (int) $r->document_id,
                        'chunk_index' => (int) $r->chunk_index,
                        'score' => (float) $scoreAppointment,
                        'excerpt' => $excerptAppointment,
                        'entity' => $label,
                    ];
                }

                continue;
            }

        foreach ($scheduleCandidates as $candidate) {
            $sched = $candidate['value'];
            $pos = $candidate['position'] ?? mb_strpos($content, $sched);
                $dist = $namePos !== false && $pos !== false ? abs($pos - $namePos) : 9999;
                if ($parts !== [] && ($namePos === false || $dist > 350)) {
                    continue;
                }
                $sim = $this->trigramSimilarity($lower, $nameLower);
                $score = $sim + max(0.0, 1.0 - min($dist, 350) / 350.0);
                $excerpt = $this->excerptAround($content, (int) ($pos !== false ? $pos : 0), 300);
                $entityLabel = $this->extractEntityLabel($content, $primaryParts ?: $parts, $namePos);
                $out[] = [
                'schedule' => trim((string) $sched),
                    'document_id' => (int) $r->document_id,
                    'chunk_index' => (int) $r->chunk_index,
                    'score' => (float) $score,
                    'excerpt' => $excerpt,
                    'entity' => $entityLabel,
                ];
            }
        }
        usort($out, fn($a,$b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    private function extractEntityLabel(string $content, array $parts, ?int $namePos): ?string
    {
        if ($parts === []) {
            return null;
        }

        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $line) {
            $normalized = trim(strip_tags($line));
            if ($normalized === '') {
                continue;
            }
            $lower = mb_strtolower($normalized);
            foreach ($parts as $term) {
                if ($term !== '' && mb_strpos($lower, $term) !== false) {
                    return preg_replace('/\s+/', ' ', $normalized);
                }
            }
        }

        if ($namePos !== null && $namePos !== false) {
            return null;
        }

        return null;
    }

    private function extractAppointmentSchedule(string $content, array $terms): ?string
    {
        $genericTerms = ['ufficio', 'uffici', 'sportello', 'sportelli', 'servizio', 'servizi', 'office', 'service'];

        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $line) {
            $normalized = trim(strip_tags($line));
            if ($normalized === '') {
                continue;
            }

            $lower = mb_strtolower($normalized);

            $hasRelevantTerm = $terms === [];
            foreach ($terms as $term) {
                $term = trim($term);
                if ($term === '' || mb_strlen($term) < 3) {
                    continue;
                }
                if (in_array($term, $genericTerms, true)) {
                    continue;
                }
                if (mb_strpos($lower, $term) !== false) {
                    $hasRelevantTerm = true;
                    break;
                }
            }

            if (! $hasRelevantTerm) {
                continue;
            }

            if (preg_match('/appuntamento|prenotazione|riceve\s+per|su\s+appuntamento|previo\s+appuntamento|solo\s+appuntamento/iu', $lower)) {
                return preg_replace('/\s+/', ' ', $normalized);
            }
        }

        return null;
    }

    private function extractAddressesFromTable(string $content): array
    {
        $candidates = [];
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $cursor = 0;
        $types = '(?:via|viale|piazza|p\.?:?zza|corso|largo|vicolo|piazzale|strada|str\.)';
        $keywords = ['indirizzo', 'ubicazione', 'sede', 'presso'];

        foreach ($lines as $line) {
            $lineLength = mb_strlen($line) + 1;
            $lineStart = mb_strpos($content, $line, $cursor);
            if ($lineStart === false) {
                $lineStart = $cursor;
            }
            $cursor = $lineStart + $lineLength;

            if (substr_count($line, '|') < 2) {
                continue;
            }

            $rawCells = explode('|', $line);
            foreach ($rawCells as $cell) {
                $normalized = preg_replace('/\s+/', ' ', trim(strip_tags($cell)));
                if ($normalized === '' || $normalized === '---') {
                    continue;
                }

                $matches = [];
                preg_match_all('/\b'.$types.'\s+[A-Za-zÀ-ÖØ-öø-ÿ\'"\-\s]{2,60}(?:,?\s+\d{1,4}[A-Za-z]?)?/iu', $normalized, $matches);

                if (! empty($matches[0])) {
                    foreach ($matches[0] as $matchValue) {
                        $cleanValue = preg_replace('/\s+/', ' ', trim($matchValue));
                        $position = mb_stripos($content, $matchValue, $lineStart);
                        if ($position === false) {
                            $position = $lineStart;
                        }

                        $candidates[] = [
                            'value' => $cleanValue,
                            'position' => $position,
                        ];
                    }
                    continue;
                }

                $lower = mb_strtolower($normalized);
                foreach ($keywords as $keyword) {
                    if (mb_strpos($lower, $keyword) !== false) {
                        $value = preg_replace('/\s+/', ' ', $normalized);
                        $position = mb_stripos($content, $normalized, $lineStart);
                        if ($position === false) {
                            $position = $lineStart;
                        }

                        $candidates[] = [
                            'value' => $value,
                            'position' => $position,
                        ];
                        break;
                    }
                }
            }
        }

        return $candidates;
    }

    private function extractSchedulesFromTable(string $content, array $terms): array
    {
        $candidates = [];
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $cursor = 0;
        $dayPattern = '/^(?:\*{0,2})?(lunedì|martedì|mercoledì|giovedì|venerdì|sabato|domenica|lun|mar|mer|gio|ven|sab|dom)(?:\*{0,2})?$/iu';

        foreach ($lines as $line) {
            $lineLength = mb_strlen($line) + 1;
            $lineStart = mb_strpos($content, $line, $cursor);
            if ($lineStart === false) {
                $lineStart = $cursor;
            }
            $cursor = $lineStart + $lineLength;

            if (substr_count($line, '|') < 2) {
                continue;
            }

            $rawCells = explode('|', $line);
            $cells = [];
            foreach ($rawCells as $cell) {
                $trimmed = trim($cell);
                if ($trimmed === '' || $trimmed === '---') {
                    continue;
                }
                $cells[] = preg_replace('/\s+/', ' ', strip_tags($trimmed));
            }

            if (count($cells) < 2) {
                continue;
            }

            $dayCell = array_shift($cells);
            if (! preg_match($dayPattern, $dayCell, $dayMatch)) {
                continue;
            }

            $times = [];
            foreach ($cells as $cell) {
                $normalized = trim($cell);
                if ($normalized === '') {
                    continue;
                }
                if (stripos($normalized, 'chiuso') !== false) {
                    $times[] = 'Chiuso';
                    continue;
                }

                if (preg_match_all('/(\d{1,2}[:\.]?\d{2})\s*[-–—]\s*(\d{1,2}[:\.]?\d{2})/', $normalized, $rangeMatches)) {
                    foreach ($rangeMatches[1] as $idx => $startTime) {
                        $endTime = $rangeMatches[2][$idx] ?? null;
                        $times[] = $endTime ? ($startTime.'-'.$endTime) : $startTime;
                    }
                    continue;
                }

                if (preg_match_all('/\d{1,2}[:\.]?\d{2}/', $normalized, $singleMatches)) {
                    $values = $singleMatches[0];
                    if (count($values) === 2) {
                        $times[] = $values[0].'-'.$values[1];
                    } elseif ($values !== []) {
                        $times[] = implode(' ', $values);
                    }
                }
            }

            $times = array_values(array_filter($times, static fn ($time) => $time !== ''));
            if ($times === []) {
                continue;
            }

            $times = array_unique($times);
            $value = ucfirst($dayMatch[1]).': '.implode('; ', $times);

            $candidates[] = [
                'value' => $value,
                'position' => $lineStart,
            ];
        }

        return $candidates;
    }

    /**
     * Valida che una stringa estratta sia effettivamente un orario e non una falsa positiva
     */
    private function isValidSchedule(string $schedule, string $content, int $offset): bool
    {
        $schedule = trim($schedule);
        
        // Rimuovi spazi multipli
        $schedule = preg_replace('/\s+/', ' ', $schedule);
        
        // Estrai contesto attorno alla posizione dell'orario (±50 caratteri)
        $contextBefore = mb_substr($content, max(0, $offset - 50), 50);
        $contextAfter = mb_substr($content, $offset + mb_strlen($schedule), 50);
        $fullContext = $contextBefore . ' ' . $schedule . ' ' . $contextAfter;
        
        // 1. Filtra anni (1900-2100)
        if (preg_match('/\b(19|20)\d{2}\b/', $schedule)) {
            return false;
        }
        
        // 2. Filtra date nel contesto (es: "12/03/2024", "12 marzo 2024")
        if (preg_match('/\b\d{1,2}[\/\-\.]\d{1,2}[\/\-\.](?:19|20)?\d{2}\b/u', $fullContext)) {
            return false;
        }
        
        // 3. Filtra se il contesto suggerisce una data
        if (preg_match('/\b(?:gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre|gen|feb|mar|apr|mag|giu|lug|ago|set|ott|nov|dic)\b/iu', $fullContext)) {
            return false;
        }
        
        // 4. Filtra numeri di telefono, codici, prezzi (solo se compaiono nell'orario stesso)
        if (preg_match('/\b(?:tel|telefono|cod|codice|€|euro|prezzo|costo|partita\s+iva|p\.iva|cf|c\.f\.)\b/iu', $schedule)) {
            return false;
        }
        
        // 5. Filtra coordinate o numeri civici
        if (preg_match('/\b(?:via|viale|corso|piazza|largo|lat|lon|coordinate)\b/iu', $fullContext)) {
            return false;
        }
        
        // 6. Valida che i numeri siano in range valido per orari
        preg_match_all('/\b(\d{1,2})(?:[:\.](\d{2}))?\b/', $schedule, $timeMatches);
        if (!empty($timeMatches[1])) {
            foreach ($timeMatches[1] as $i => $hour) {
                $minute = $timeMatches[2][$i] ?? '00';
                $h = (int) $hour;
                $m = (int) $minute;
                
                // Ore valide: 0-23, Minuti validi: 0-59
                if ($h > 23 || $m > 59) {
                    return false;
                }
                
                // Filtra ore molto improbabili per orari di servizio (es: 1:00, 2:00, 3:00)
                if ($h < 6 && !preg_match('/\b(?:notturno|24|continuo|emergenza)\b/iu', $fullContext)) {
                    return false;
                }
            }
        }
        
        // 7. Deve avere indicatori di contesto temporale
        $timeIndicators = [
            'orario', 'orari', 'apertura', 'chiusura', 'aperto', 'chiuso',
            'ore', 'dalle', 'alle', 'fino', 'mattina', 'pomeriggio', 'sera',
            'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato', 'domenica',
            'lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom',
            'ricevimento', 'sportello', 'ufficio', 'servizio'
        ];
        
        $hasTimeContext = false;
        foreach ($timeIndicators as $indicator) {
            if (preg_match('/\b' . preg_quote($indicator, '/') . '\b/iu', $fullContext)) {
                $hasTimeContext = true;
                break;
            }
        }
        
        return $hasTimeContext;
    }

    /**
     * Filtra potenziali falsi positivi (partite IVA, codici fiscali, date, etc.)
     */
    /**
     * Costruisce condizioni SQL OR per matchare qualsiasi combinazione di termini sinonimi
     * 
     * Esempio: ["vigili", "urbani", "polizia", "locale"] genera:
     * OR (LOWER(dc.content) ILIKE '%vigili%' AND LOWER(dc.content) ILIKE '%polizia%')
     * OR (LOWER(dc.content) ILIKE '%vigili%' AND LOWER(dc.content) ILIKE '%locale%')
     * OR (LOWER(dc.content) ILIKE '%urbani%' AND LOWER(dc.content) ILIKE '%polizia%')
     * ...
     * 
     * @param  array<string>  $terms  Array di termini (nome originale + sinonimi)
     * @param  bool  $useTableAlias  Se true usa "dc.content", altrimenti "content"
     * @return array{sql: string, params: array<string, string>}
     */
    private function buildSynonymMatchingConditions(array $terms, bool $useTableAlias = false): array
    {
        if (count($terms) < 2) {
            return ['sql' => '', 'params' => []];
        }

        $contentField = $useTableAlias ? 'dc.content' : 'content';
        $conditions = [];
        $params = [];
        $paramIndex = 0;

        // Genera tutte le combinazioni di coppie di termini (massimo 10 per evitare query troppo lunghe)
        $maxPairs = min(10, (count($terms) * (count($terms) - 1)) / 2);
        $pairsGenerated = 0;

        for ($i = 0; $i < count($terms) && $pairsGenerated < $maxPairs; $i++) {
            for ($j = $i + 1; $j < count($terms) && $pairsGenerated < $maxPairs; $j++) {
                $term1 = trim($terms[$i]);
                $term2 = trim($terms[$j]);
                
                // Skip termini troppo corti o identici
                if (mb_strlen($term1) < 2 || mb_strlen($term2) < 2 || $term1 === $term2) {
                    continue;
                }

                $key1 = "syn_term_{$paramIndex}_1";
                $key2 = "syn_term_{$paramIndex}_2";
                
                $conditions[] = "(LOWER({$contentField}) ILIKE :{$key1} AND LOWER({$contentField}) ILIKE :{$key2})";
                $params[$key1] = '%'.$term1.'%';
                $params[$key2] = '%'.$term2.'%';
                
                $paramIndex++;
                $pairsGenerated++;
            }
        }

        $sql = !empty($conditions) ? 'OR '.implode(' OR ', $conditions) : '';

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }

    private function isLikelyNotPhone(string $phone, string $content, string $originalMatch): bool
    {
        // Rimuovi prefisso internazionale per i controlli
        $cleaned = preg_replace('/^(\+39|0039)/', '', $phone);
        
        // Numeri di emergenza sono sempre validi
        if (preg_match('/^(?:112|113|115|117|118|1515|1530|1533|114)$/', $cleaned)) {
            return false;
        }
        
        // Partita IVA: esattamente 11 cifre
        if (strlen($cleaned) === 11 && ctype_digit($cleaned)) {
            // Cerca indicatori di partita IVA nel contesto
            $context = mb_strtolower(substr($content, max(0, strpos($content, $originalMatch) - 50), 200));
            if (preg_match('/\b(?:p\.?iva|partita\s+iva|vat|codice\s+fiscale|cf)\b/', $context)) {
                return true;
            }
        }
        
        // Codici fiscali: 16 caratteri alfanumerici
        if (strlen($originalMatch) === 16 && preg_match('/^[A-Z0-9]{16}$/i', str_replace([' ', '-', '.'], '', $originalMatch))) {
            return true;
        }
        
        // Date in vari formati
        if (preg_match('/^\d{1,2}[\.\-\/]\d{1,2}[\.\-\/]\d{2,4}$/', $originalMatch) ||
            preg_match('/^\d{2,4}[\.\-\/]\d{1,2}[\.\-\/]\d{1,2}$/', $originalMatch) ||
            preg_match('/^\d{4}\d{2}\d{2}$/', $cleaned)) {
            return true;
        }
        
        // Orari (es: 08:30, 14.45)
        if (preg_match('/^\d{1,2}[\.\:]\d{2}$/', $originalMatch)) {
            return true;
        }
        
        // Codici postali: esattamente 5 cifre
        if (strlen($cleaned) === 5 && ctype_digit($cleaned)) {
            $context = mb_strtolower(substr($content, max(0, strpos($content, $originalMatch) - 30), 100));
            if (preg_match('/\b(?:cap|codice\s+postale|via|viale|piazza|corso)\b/', $context)) {
                return true;
            }
        }
        
        // Prezzi/importi (se preceduto da € o seguito da €, EUR, ecc.)
        $pos = strpos($content, $originalMatch);
        if ($pos !== false) {
            $contextBefore = substr($content, max(0, $pos - 10), 15);
            $contextAfter = substr($content, $pos + strlen($originalMatch), 15);
            $fullContext = $contextBefore . $originalMatch . $contextAfter;
            
            if (preg_match('/[€$£]\s*' . preg_quote($originalMatch, '/') . '|\b' . preg_quote($originalMatch, '/') . '\s*(?:€|eur|euro|dollari?|pounds?)\b/i', $fullContext)) {
                return true;
            }
        }
        

        
        // Numeri di documento/protocollo (troppo lunghi o troppo corti, eccetto emergenze)
        if (strlen($cleaned) < 6 || strlen($cleaned) > 15) {
            return true;
        }
        
        // Telefoni validi devono iniziare con cifre appropriate
        if (!preg_match('/^(?:0\d{1,3}|3\d{2}|800|199|892|899|1\d{2,3})/', $cleaned)) {
            return true;
        }
        
        return false;
    }
}




