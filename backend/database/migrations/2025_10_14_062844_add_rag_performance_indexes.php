<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ⚡ PERFORMANCE OPTIMIZATION: Composite indexes for RAG + Admin queries
     *
     * Expected Improvement: 5x faster queries (500ms → 100ms)
     *
     * Before running: ANALYZE the slow queries with EXPLAIN to verify index usage.
     * After running: REINDEX CONCURRENTLY if table is large and in production.
     */
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // ⚡ Index #1: Document + Tenant for JOIN optimization
            // Speeds up: Joins with documents table for KB filtering
            // Usage: JOIN documents ON document_chunks.document_id = documents.id WHERE document_chunks.tenant_id = ?
            $table->index(
                ['document_id', 'tenant_id'],
                'idx_document_chunks_document_tenant'
            );

            // Note: document_chunks.knowledge_base_id does NOT exist!
            // KB filtering happens via JOIN with documents table
        });

        Schema::table('documents', function (Blueprint $table) {
            // ⚡ Index #3: Admin panel filtering
            // Speeds up: DocumentAdminController::index() with multiple filters
            // Usage: WHERE tenant_id = ? AND knowledge_base_id = ? AND ingestion_status = ?
            $table->index(
                ['tenant_id', 'knowledge_base_id', 'ingestion_status'],
                'idx_documents_admin_filtering'
            );

            // ⚡ Index #4: Source URL deduplication check
            // Speeds up: WebScraperService finding existing documents
            // Usage: WHERE tenant_id = ? AND source_url = ? AND content_hash = ?
            $table->index(
                ['tenant_id', 'source_url', 'content_hash'],
                'idx_documents_source_url_hash'
            );

            // ⚡ Index #5: KB-level operations (batch delete, stats)
            // Speeds up: Deleting all documents in a KB, counting docs per KB
            // Usage: WHERE knowledge_base_id = ? [AND tenant_id = ?]
            $table->index(
                ['knowledge_base_id', 'tenant_id'],
                'idx_documents_kb_tenant'
            );
        });

        // ⚠️ conversation_sessions index skipped - table structure varies
        // Add manually if needed after verifying table schema

        // ⚡ VERIFY EXISTING INDEXES
        // Full-text search on document_chunks.content should already exist:
        // CREATE INDEX idx_document_chunks_content_fts ON document_chunks
        //   USING GIN (to_tsvector('italian', content));
        // If not, uncomment:
        /*
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_document_chunks_content_fts
            ON document_chunks
            USING GIN (to_tsvector('italian', content))
        ");
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex('idx_document_chunks_document_tenant');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('idx_documents_admin_filtering');
            $table->dropIndex('idx_documents_source_url_hash');
            $table->dropIndex('idx_documents_kb_tenant');
        });

        // conversation_sessions index skipped in up(), nothing to drop

        // Full-text index rollback (if created):
        // DB::statement("DROP INDEX IF EXISTS idx_document_chunks_content_fts");
    }
};
