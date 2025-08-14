<?php

namespace App\Console\Commands;

use App\Services\RAG\MilvusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestMilvusPartition extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milvus:test-partition {tenant_id : ID del tenant} {--force : Forza la creazione anche se esiste}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa la creazione di una partizione Milvus per un tenant specifico';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $force = $this->option('force');

        if ($tenantId <= 0) {
            $this->error('ID tenant deve essere un numero positivo');
            return 1;
        }

        $partitionName = "tenant_{$tenantId}";
        
        $this->info("Testando creazione partizione Milvus...");
        $this->info("Tenant ID: {$tenantId}");
        $this->info("Partizione: {$partitionName}");
        $this->info("Sistema: " . PHP_OS_FAMILY);
        
        // Verifica configurazione
        $partitionsEnabled = config('rag.vector.milvus.partitions_enabled', true);
        if (!$partitionsEnabled) {
            $this->warn('⚠️  Le partizioni Milvus sono disabilitate nella configurazione');
            $this->info('Per abilitarle: MILVUS_PARTITIONS_ENABLED=true nel .env');
            return 0;
        }

        try {
            $client = app(MilvusClient::class);
            
            // Verifica se esiste già
            if (!$force && $client->hasPartition($partitionName)) {
                $this->info("✅ Partizione '{$partitionName}' già esistente");
                return 0;
            }

            // Tenta la creazione
            $this->info("Creando partizione '{$partitionName}'...");
            $client->createPartition($partitionName);
            
            $this->info("✅ Partizione creata con successo!");
            
        } catch (\Throwable $e) {
            $this->error("❌ Errore durante la creazione della partizione:");
            $this->error("Classe: " . get_class($e));
            $this->error("Messaggio: " . $e->getMessage());
            
            if (PHP_OS_FAMILY === 'Windows') {
                $this->warn("🔧 Su Windows, considera di disabilitare le partizioni:");
                $this->warn("   MILVUS_PARTITIONS_ENABLED=false nel .env");
            }
            
            $this->info("\n💡 Suggerimenti:");
            $this->info("- Verifica che Milvus sia in esecuzione");
            $this->info("- Controlla la configurazione in config/rag.php");
            $this->info("- Verifica i log in storage/logs/laravel.log");
            
            return 1;
        }

        return 0;
    }
}
