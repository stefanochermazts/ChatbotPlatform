<?php

namespace App\Console\Commands;

use App\Jobs\CreateMilvusPartitionJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

class CreateMilvusPartitionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'milvus:create-partitions 
                            {--tenant-id= : ID specifico del tenant}
                            {--all : Crea partizioni per tutti i tenant}
                            {--missing-only : Solo per tenant senza partizione}';

    /**
     * The console command description.
     */
    protected $description = 'Crea partizioni Milvus per tenant esistenti';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant-id');
        $all = $this->option('all');
        $missingOnly = $this->option('missing-only');

        if (! $tenantId && ! $all) {
            $this->error('Devi specificare --tenant-id=X o --all');

            return 1;
        }

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                $this->error("Tenant con ID {$tenantId} non trovato");

                return 1;
            }

            $this->createPartitionForTenant($tenant);

            return 0;
        }

        if ($all) {
            $tenants = Tenant::all();
            $this->info("Trovati {$tenants->count()} tenant");

            foreach ($tenants as $tenant) {
                $this->createPartitionForTenant($tenant);
            }

            $this->info('Completato! Verifica i log per eventuali errori.');

            return 0;
        }

        return 0;
    }

    private function createPartitionForTenant(Tenant $tenant): void
    {
        $partitionName = "tenant_{$tenant->id}";

        $this->info("Creando partizione '{$partitionName}' per tenant '{$tenant->name}' (ID: {$tenant->id})");

        try {
            CreateMilvusPartitionJob::dispatch($tenant->id);
            $this->info("✓ Job accodato per tenant {$tenant->id}");
        } catch (\Throwable $e) {
            $this->error("✗ Errore per tenant {$tenant->id}: ".$e->getMessage());
        }
    }
}
