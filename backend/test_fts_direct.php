<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== DIRECT POSTGRESQL FTS TEST ===" . PHP_EOL . PHP_EOL;

$query = "telefono comando polizia locale";
$tenantId = 5;
$k = 20;

echo "Query: \"{$query}\"" . PHP_EOL;
echo "Tenant ID: {$tenantId}" . PHP_EOL;
echo PHP_EOL;

try {
    // Test exact SQL from TextSearchService
    $sql = <<<SQL
        WITH q AS (SELECT plainto_tsquery('simple', :q) AS tsq)
        SELECT dc.document_id, dc.chunk_index,
               ts_rank(to_tsvector('simple', dc.content), q.tsq) AS score,
               LEFT(dc.content, 200) as content_preview
        FROM document_chunks dc
        INNER JOIN documents d ON d.id = dc.document_id
        , q
        WHERE dc.tenant_id = :tenant
          AND d.tenant_id = :tenant
          AND to_tsvector('simple', dc.content) @@ q.tsq
        ORDER BY score DESC
        LIMIT :k
    SQL;
    
    echo "Executing PostgreSQL FTS query..." . PHP_EOL;
    echo PHP_EOL;
    
    $rows = DB::select($sql, [
        'q' => $query,
        'tenant' => $tenantId,
        'k' => $k,
    ]);
    
    if (empty($rows)) {
        echo "❌ NO RESULTS from PostgreSQL FTS!" . PHP_EOL;
        echo PHP_EOL;
        echo "🔍 DEBUGGING INFO:" . PHP_EOL;
        
        // Test 1: Check total chunks for tenant
        $totalChunks = DB::selectOne("SELECT COUNT(*) as count FROM document_chunks WHERE tenant_id = ?", [$tenantId]);
        echo "  - Total chunks for tenant {$tenantId}: " . ($totalChunks->count ?? 0) . PHP_EOL;
        
        // Test 2: Check doc 4350 chunks
        $doc4350Chunks = DB::selectOne("SELECT COUNT(*) as count FROM document_chunks WHERE document_id = 4350", []);
        echo "  - Chunks for doc 4350: " . ($doc4350Chunks->count ?? 0) . PHP_EOL;
        
        // Test 3: Try simpler query (just "polizia")
        echo PHP_EOL;
        echo "Testing simpler query: 'polizia'..." . PHP_EOL;
        $simpleSql = <<<SQL
            WITH q AS (SELECT plainto_tsquery('simple', 'polizia') AS tsq)
            SELECT dc.document_id, dc.chunk_index,
                   ts_rank(to_tsvector('simple', dc.content), q.tsq) AS score
            FROM document_chunks dc
            INNER JOIN documents d ON d.id = dc.document_id
            , q
            WHERE dc.tenant_id = :tenant
              AND d.tenant_id = :tenant
              AND to_tsvector('simple', dc.content) @@ q.tsq
            ORDER BY score DESC
            LIMIT 10
        SQL;
        
        $simpleRows = DB::select($simpleSql, ['tenant' => $tenantId]);
        echo "  - Results for 'polizia': " . count($simpleRows) . PHP_EOL;
        
        if (!empty($simpleRows)) {
            echo "  - Top result: Doc " . $simpleRows[0]->document_id . ", Chunk " . $simpleRows[0]->chunk_index . ", Score " . round($simpleRows[0]->score, 4) . PHP_EOL;
        }
        
        // Test 4: Check tsquery output
        echo PHP_EOL;
        echo "Testing tsquery conversion..." . PHP_EOL;
        $tsqueryTest = DB::selectOne("SELECT plainto_tsquery('simple', ?) as tsq", [$query]);
        echo "  - plainto_tsquery('{$query}'): " . ($tsqueryTest->tsq ?? 'NULL') . PHP_EOL;
        
        exit(1);
    }
    
    echo "✅ FOUND " . count($rows) . " RESULTS!" . PHP_EOL;
    echo PHP_EOL;
    
    $doc4350Found = false;
    
    foreach ($rows as $i => $row) {
        $position = $i + 1;
        echo "Result #{$position}:" . PHP_EOL;
        echo "  Document ID: {$row->document_id}" . PHP_EOL;
        echo "  Chunk Index: {$row->chunk_index}" . PHP_EOL;
        echo "  FTS Score: " . round($row->score, 4) . PHP_EOL;
        echo "  Content: " . str_replace(["\n", "\r"], ' ', $row->content_preview) . "..." . PHP_EOL;
        echo PHP_EOL;
        
        if ($row->document_id == 4350) {
            $doc4350Found = true;
        }
    }
    
    if ($doc4350Found) {
        echo "✅ Document 4350 FOUND in FTS results!" . PHP_EOL;
    } else {
        echo "❌ Document 4350 NOT FOUND in FTS results!" . PHP_EOL;
    }
    
    exit($doc4350Found ? 0 : 1);
    
} catch (\Throwable $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}

