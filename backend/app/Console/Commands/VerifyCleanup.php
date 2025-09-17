<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Tenant;
use App\Services\RAG\MilvusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyCleanup extends Command
{
    protected $signature = 'cleanup:verify 
                            {tenant : Tenant ID to verify}
                            {--kb= : Specific KB ID to check}
                            {--fix : Fix inconsistencies found}';

    protected $description = 'Verify complete cleanup of documents, chunks, and vectors';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $kbId = $this->option('kb') ? (int) $this->option('kb') : null;
        $fix = $this->option('fix');

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("❌ Tenant {$tenantId} not found");
            return 1;
        }

        $this->info("🔍 Verifying cleanup for Tenant: {$tenant->name} (ID: {$tenantId})");
        if ($kbId) {
            $this->info("📋 Focusing on KB ID: {$kbId}");
        }
        $this->newLine();

        // 1. Verifica documenti PostgreSQL
        $this->verifyDocuments($tenantId, $kbId, $fix);
        
        // 2. Verifica chunks PostgreSQL
        $this->verifyChunks($tenantId, $kbId, $fix);
        
        // 3. Verifica vettori Milvus
        $this->verifyMilvusVectors($tenantId, $fix);
        
        // 4. Summary finale
        $this->displaySummary($tenantId, $kbId);

        return 0;
    }

    private function verifyDocuments(int $tenantId, ?int $kbId, bool $fix): void
    {
        $this->line("📄 <comment>Checking Documents...</comment>");
        
        $query = Document::where('tenant_id', $tenantId);
        if ($kbId) {
            $query->where('knowledge_base_id', $kbId);
        }
        
        $documents = $query->get();
        $orphanedFiles = [];
        
        foreach ($documents as $doc) {
            // Verifica se il file esiste
            if ($doc->path && !\Storage::disk('public')->exists($doc->path)) {
                $orphanedFiles[] = $doc;
            }
        }
        
        $this->line("   • Documents in DB: {$documents->count()}");
        $this->line("   • Orphaned files: " . count($orphanedFiles));
        
        if (!empty($orphanedFiles)) {
            $this->warn("   ⚠️  Found " . count($orphanedFiles) . " documents with missing files");
            
            if ($fix) {
                foreach ($orphanedFiles as $doc) {
                    $this->line("   🔧 Cleaning up document: {$doc->title}");
                    DB::table('document_chunks')->where('document_id', $doc->id)->delete();
                    $doc->delete();
                }
                $this->info("   ✅ Cleaned up orphaned documents");
            } else {
                $this->line("   💡 Run with --fix to clean up orphaned documents");
            }
        }
    }

    private function verifyChunks(int $tenantId, ?int $kbId, bool $fix): void
    {
        $this->line("🧩 <comment>Checking Document Chunks...</comment>");
        
        // Trova chunks orfani (senza documento corrispondente)
        $orphanedChunks = DB::table('document_chunks as dc')
            ->leftJoin('documents as d', 'dc.document_id', '=', 'd.id')
            ->whereNull('d.id')
            ->where('dc.tenant_id', $tenantId);
            
        if ($kbId) {
            $orphanedChunks->where('dc.knowledge_base_id', $kbId);
        }
            
        $orphanedCount = $orphanedChunks->count();
        
        // Conta chunks totali
        $totalChunks = DB::table('document_chunks')
            ->where('tenant_id', $tenantId);
        if ($kbId) {
            $totalChunks->where('knowledge_base_id', $kbId);
        }
        $totalChunks = $totalChunks->count();
        
        $this->line("   • Total chunks: {$totalChunks}");
        $this->line("   • Orphaned chunks: {$orphanedCount}");
        
        if ($orphanedCount > 0) {
            $this->warn("   ⚠️  Found {$orphanedCount} orphaned chunks");
            
            if ($fix) {
                $deletedChunks = DB::table('document_chunks as dc')
                    ->leftJoin('documents as d', 'dc.document_id', '=', 'd.id')
                    ->whereNull('d.id')
                    ->where('dc.tenant_id', $tenantId);
                if ($kbId) {
                    $deletedChunks->where('dc.knowledge_base_id', $kbId);
                }
                $deletedChunks->delete();
                
                $this->info("   ✅ Cleaned up {$orphanedCount} orphaned chunks");
            } else {
                $this->line("   💡 Run with --fix to clean up orphaned chunks");
            }
        }
    }

    private function verifyMilvusVectors(int $tenantId, bool $fix): void
    {
        $this->line("🔍 <comment>Checking Milvus Vectors...</comment>");
        
        try {
            $milvus = app(MilvusClient::class);
            $health = $milvus->health();
            
            if (!$health['connected']) {
                $this->error("   ❌ Milvus not connected");
                return;
            }
            
            $vectorCount = $milvus->countByTenant($tenantId);
            $this->line("   • Vectors in Milvus: {$vectorCount}");
            
            // Conta chunks corrispondenti in PostgreSQL
            $expectedChunks = DB::table('document_chunks')
                ->where('tenant_id', $tenantId)
                ->count();
                
            $this->line("   • Expected chunks: {$expectedChunks}");
            
            $difference = abs($vectorCount - $expectedChunks);
            if ($difference > 0) {
                $this->warn("   ⚠️  Mismatch: {$difference} vectors difference");
                
                if ($fix) {
                    if ($vectorCount > $expectedChunks) {
                        // Più vettori che chunks - cancella vettori in eccesso
                        $this->line("   🔧 Cleaning up excess vectors in Milvus...");
                        
                        // Get document IDs che dovrebbero esistere
                        $validDocIds = DB::table('documents')
                            ->where('tenant_id', $tenantId)
                            ->pluck('id')
                            ->toArray();
                            
                        // TODO: Implementa cleanup selettivo in Milvus
                        $this->warn("   ⚠️  Selective Milvus cleanup not yet implemented");
                    } else {
                        // Meno vettori che chunks - reprocessa documenti
                        $this->line("   🔧 Reprocessing documents for missing vectors...");
                        
                        $documentsToReprocess = Document::where('tenant_id', $tenantId)
                            ->where('ingestion_status', 'completed')
                            ->limit(10) // Limita per evitare sovraccarico
                            ->get();
                            
                        foreach ($documentsToReprocess as $doc) {
                            $doc->update(['ingestion_status' => 'pending']);
                            \App\Jobs\IngestUploadedDocumentJob::dispatch($doc->id);
                        }
                        
                        $this->info("   ✅ Queued {$documentsToReprocess->count()} documents for reprocessing");
                    }
                } else {
                    $this->line("   💡 Run with --fix to attempt correction");
                }
            } else {
                $this->info("   ✅ Milvus vectors match expected chunks");
            }
            
        } catch (\Exception $e) {
            $this->error("   ❌ Error checking Milvus: " . $e->getMessage());
        }
    }

    private function displaySummary(int $tenantId, ?int $kbId): void
    {
        $this->newLine();
        $this->info("📊 <comment>Cleanup Summary</comment>");
        
        // Count finale
        $documents = Document::where('tenant_id', $tenantId);
        if ($kbId) {
            $documents->where('knowledge_base_id', $kbId);
        }
        $docCount = $documents->count();
        
        $chunks = DB::table('document_chunks')->where('tenant_id', $tenantId);
        if ($kbId) {
            $chunks->where('knowledge_base_id', $kbId);
        }
        $chunkCount = $chunks->count();
        
        try {
            $milvus = app(MilvusClient::class);
            $vectorCount = $milvus->countByTenant($tenantId);
        } catch (\Exception $e) {
            $vectorCount = 'Error';
        }
        
        $this->table(['Component', 'Count'], [
            ['Documents (PostgreSQL)', $docCount],
            ['Chunks (PostgreSQL)', $chunkCount],
            ['Vectors (Milvus)', $vectorCount],
        ]);
        
        if ($docCount === 0 && $chunkCount === 0 && $vectorCount === 0) {
            $this->info("🎉 <comment>Complete cleanup verified - all data removed!</comment>");
        } elseif ($docCount === $chunkCount && $chunkCount == $vectorCount) {
            $this->info("✅ <comment>Data consistency verified - all components aligned!</comment>");
        } else {
            $this->warn("⚠️  <comment>Data inconsistencies detected - run with --fix to resolve</comment>");
        }
    }
}





