<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    // Evita transazione per CREATE EXTENSION in alcuni ambienti
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_document_chunks_trgm ON document_chunks USING gin (content gin_trgm_ops)');
    }

    public function down(): void
    {
        // Lascio l'estensione installata; rimuovo solo l'indice
        DB::statement('DROP INDEX IF EXISTS idx_document_chunks_trgm');
    }
};


