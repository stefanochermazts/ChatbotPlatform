<?php

namespace App\Console\Commands;

use App\Services\RAG\MilvusClient;
use Illuminate\Console\Command;

class MilvusHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milvus:health {--detailed : Mostra informazioni dettagliate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica lo stato di salute della connessione Milvus';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $detailed = $this->option('detailed');

        $this->info('🔍 Controllo stato Milvus...');
        $this->info('Sistema: '.PHP_OS_FAMILY);

        // Mostra configurazione
        if ($detailed) {
            $this->info("\n📋 Configurazione:");
            $config = config('rag.vector.milvus');
            $this->table(['Parametro', 'Valore'], [
                ['Host', $config['host'] ?? 'non configurato'],
                ['Port', $config['port'] ?? 'non configurato'],
                ['URI', ! empty($config['uri']) ? '***configurato***' : 'non configurato'],
                ['Token', ! empty($config['token']) ? '***configurato***' : 'non configurato'],
                ['Collection', $config['collection'] ?? 'non configurato'],
                ['Partizioni abilitate', $config['partitions_enabled'] ? 'Sì' : 'No'],
                ['TLS', $config['tls'] ? 'Sì' : 'No'],
            ]);
        }

        try {
            $client = app(MilvusClient::class);

            $this->info("\n🔗 Testando connessione...");
            $health = $client->health();

            if ($health['ok'] ?? false) {
                $this->info('✅ Connessione Milvus OK');

                if ($detailed) {
                    $this->info("\n📊 Test aggiuntivi:");

                    // Test lista partizioni se supportato
                    try {
                        $collection = config('rag.vector.milvus.collection', 'kb_chunks_v1');

                        // Prova a verificare se hasPartition funziona
                        $hasPartitionTest = $client->hasPartition('test_partition_non_esistente');
                        $this->info('- hasPartition() funziona: ✅');

                    } catch (\Throwable $e) {
                        $this->warn('- hasPartition() non supportato o errore: '.$e->getMessage());
                    }
                }

                return 0;
            } else {
                $this->error('❌ Connessione Milvus fallita');
                if (isset($health['error'])) {
                    $this->error('Errore: '.$health['error']);
                }

                return 1;
            }

        } catch (\Throwable $e) {
            $this->error('❌ Errore durante il test di connessione:');
            $this->error('Classe: '.get_class($e));
            $this->error('Messaggio: '.$e->getMessage());

            if ($detailed) {
                $this->error("\nStack trace:");
                $this->error($e->getTraceAsString());
            }

            $this->info("\n💡 Suggerimenti:");
            $this->info('- Verifica che Milvus sia in esecuzione');
            $this->info('- Controlla host/porta in .env');
            $this->info('- Per Zilliz Cloud, verifica URI e TOKEN');
            $this->info('- Controlla i log in storage/logs/laravel.log');

            if (PHP_OS_FAMILY === 'Windows') {
                $this->warn("\n🔧 Su Windows, potresti dover:");
                $this->warn('- Usare Milvus in Docker');
                $this->warn('- Disabilitare le partizioni: MILVUS_PARTITIONS_ENABLED=false');
            }

            return 1;
        }
    }
}
