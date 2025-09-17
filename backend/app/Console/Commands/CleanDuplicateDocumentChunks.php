<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanDuplicateDocumentChunks extends Command
{
    protected $signature = 'chunks:clean-duplicates {--tenant= : Tenant ID to clean (optional)} {--dry-run : Only show what would be cleaned}';
    protected $description = 'Clean duplicate document chunks caused by race conditions';

    public function handle()
    {
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $this->info('🧹 Cleaning duplicate document chunks...');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
        }

        // Find duplicates query
        $duplicatesQuery = DB::table('document_chunks as dc1')
            ->select('dc1.document_id', 'dc1.chunk_index', DB::raw('COUNT(*) as count'))
            ->groupBy('dc1.document_id', 'dc1.chunk_index')
            ->havingRaw('COUNT(*) > 1');

        if ($tenantId) {
            $duplicatesQuery->where('dc1.tenant_id', $tenantId);
            $this->info("🎯 Filtering by tenant ID: {$tenantId}");
        }

        $duplicates = $duplicatesQuery->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate chunks found!');
            return 0;
        }

        $this->warn("⚠️  Found {$duplicates->count()} sets of duplicate chunks");

        $totalCleaned = 0;
        $progressBar = $this->output->createProgressBar($duplicates->count());

        foreach ($duplicates as $duplicate) {
            $documentId = $duplicate->document_id;
            $chunkIndex = $duplicate->chunk_index;
            $count = $duplicate->count;

            $this->newLine();
            $this->info("🔍 Processing document {$documentId}, chunk {$chunkIndex} ({$count} duplicates)");

            // Get all duplicates for this document_id + chunk_index
            $chunksQuery = DB::table('document_chunks')
                ->where('document_id', $documentId)
                ->where('chunk_index', $chunkIndex)
                ->orderBy('created_at', 'asc') // Keep the oldest one
                ->orderBy('id', 'asc');

            if ($tenantId) {
                $chunksQuery->where('tenant_id', $tenantId);
            }

            $chunks = $chunksQuery->get();

            if ($chunks->count() <= 1) {
                $this->comment("  ℹ️  No duplicates found for this chunk (already cleaned?)");
                continue;
            }

            // Keep the first (oldest) chunk, delete the rest
            $keepChunk = $chunks->first();
            $deleteChunks = $chunks->slice(1);

            $this->comment("  ✅ Keeping chunk ID {$keepChunk->id} (created: {$keepChunk->created_at})");
            
            foreach ($deleteChunks as $deleteChunk) {
                $this->comment("  🗑️  Deleting chunk ID {$deleteChunk->id} (created: {$deleteChunk->created_at})");
                
                if (!$dryRun) {
                    DB::table('document_chunks')->where('id', $deleteChunk->id)->delete();
                    $totalCleaned++;
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $wouldClean = $duplicates->sum('count') - $duplicates->count();
            $this->info("🔍 DRY RUN COMPLETE - Would have cleaned {$wouldClean} duplicate chunks");
        } else {
            $this->info("✅ CLEANUP COMPLETE - Cleaned {$totalCleaned} duplicate chunks");
            
            Log::info('chunks.cleanup_completed', [
                'tenant_id' => $tenantId,
                'duplicates_found' => $duplicates->count(),
                'chunks_cleaned' => $totalCleaned,
                'dry_run' => $dryRun
            ]);
        }

        return 0;
    }
}