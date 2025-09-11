<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MilvusMigrateSchema extends Command
{
    protected $signature = 'milvus:migrate-schema 
                            {--backup : Create backup only}
                            {--recreate : Recreate schema only}
                            {--restore : Restore from backup only}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Migrate Milvus collection to new schema with dynamic fields enabled';

    public function handle(): int
    {
        $config = config('rag.vector.milvus');
        $collectionName = $config['collection'] ?? 'kb_chunks_v1';
        $pythonPath = $config['python_path'] ?? 'python';
        $script = base_path('recreate_milvus_collection.py');

        if (!file_exists($script)) {
            $this->error("Python script not found: {$script}");
            return 1;
        }

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info("🔍 DRY RUN - No actual changes will be made");
        }

        // Determina operazione
        $operation = 'full_migration';
        if ($this->option('backup')) {
            $operation = 'backup';
        } elseif ($this->option('recreate')) {
            $operation = 'recreate';
        } elseif ($this->option('restore')) {
            $operation = 'restore';
        }

        $this->info("🚀 Starting Milvus schema migration...");
        $this->info("📋 Collection: {$collectionName}");
        $this->info("⚡ Operation: {$operation}");

        if ($isDryRun) {
            $this->info("✅ Dry run completed - would execute: {$operation} on {$collectionName}");
            return 0;
        }

        // Conferma per operazioni pericolose
        if (in_array($operation, ['recreate', 'full_migration'])) {
            if (!$this->confirm("⚠️  This will DROP and RECREATE the Milvus collection. Continue?")) {
                $this->info("❌ Operation cancelled by user");
                return 1;
            }
        }

        // Esegui script Python
        $command = "\"{$pythonPath}\" \"{$script}\" \"{$operation}\" \"{$collectionName}\" 2>&1";
        $this->info("🐍 Executing: {$command}");

        $output = shell_exec($command);
        
        if (empty($output)) {
            $this->error("❌ No output from Python script");
            return 1;
        }

        // Separa stderr (progress) da stdout (JSON result)
        $lines = explode("\n", trim($output));
        
        // Trova dove inizia il JSON (dalla prima riga che contiene {)
        $jsonLines = [];
        $jsonStarted = false;
        
        foreach ($lines as $line) {
            if (str_contains($line, '{')) {
                $jsonStarted = true;
            }
            
            if ($jsonStarted) {
                $jsonLines[] = $line;
            } else if (!empty(trim($line))) {
                $this->line("  📊 {$line}");
            }
        }
        
        $jsonString = implode("\n", $jsonLines);
        $result = json_decode($jsonString, true);
        
        if (!$result) {
            $this->error("❌ Invalid JSON response from Python script:");
            $this->line("Raw output:");
            $this->line($output);
            return 1;
        }

        if (!$result['success']) {
            $this->error("❌ Migration failed: " . ($result['error'] ?? 'Unknown error'));
            Log::error('milvus.migration_failed', $result);
            return 1;
        }

        // Log risultati per ogni step
        if ($operation === 'full_migration') {
            $backup = $result['backup'] ?? [];
            $recreate = $result['recreate'] ?? [];
            $restore = $result['restore'] ?? [];

            $this->info("✅ Backup: " . ($backup['records_backed_up'] ?? 0) . " records");
            $this->info("✅ Schema recreated with dynamic fields enabled");
            $this->info("✅ Restored: " . ($restore['restored_records'] ?? 0) . " records");

            Log::info('milvus.migration_completed', [
                'collection' => $collectionName,
                'backup_records' => $backup['records_backed_up'] ?? 0,
                'restored_records' => $restore['restored_records'] ?? 0
            ]);
        } else {
            $this->info("✅ {$operation} completed successfully");
            Log::info("milvus.{$operation}_completed", $result);
        }

        $this->info("🎉 Milvus schema migration completed!");
        
        return 0;
    }
}
