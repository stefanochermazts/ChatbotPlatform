<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("CREATE INDEX IF NOT EXISTS document_chunks_fts_idx ON document_chunks USING GIN (to_tsvector('simple', content))");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS document_chunks_fts_idx");
    }
};



