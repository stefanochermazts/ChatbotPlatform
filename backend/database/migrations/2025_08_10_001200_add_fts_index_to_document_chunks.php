<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Skip GIN index creation in testing environment (Windows compatibility)
        // BM25 queries will still work, just slower (sequential scan)
        if (app()->environment('testing')) {
            Log::info('Skipping GIN FTS index creation in testing environment (Windows compatibility)');

            return;
        }

        DB::statement("CREATE INDEX IF NOT EXISTS document_chunks_fts_idx ON document_chunks USING GIN (to_tsvector('simple', content))");
    }

    public function down(): void
    {
        // Skip index drop in testing environment
        if (app()->environment('testing')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS document_chunks_fts_idx');
    }
};
