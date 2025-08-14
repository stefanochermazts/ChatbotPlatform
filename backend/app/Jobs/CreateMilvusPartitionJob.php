<?php

namespace App\Jobs;

use App\Services\RAG\MilvusClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CreateMilvusPartitionJob implements ShouldQueue
{
    use Queueable;

    public int $tenantId;
    public int $tries = 3;
    public int $backoff = 30; // secondi

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->queue = 'default';
    }

    public function handle(): void
    {
        $collectionName = config('rag.vector.milvus.collection', 'kb_chunks_v1');
        $partitionName = "tenant_{$this->tenantId}";

        Log::info('milvus.partition.creating', [
            'tenant_id' => $this->tenantId,
            'collection' => $collectionName,
            'partition' => $partitionName,
        ]);

        try {
            // Prima prova a creare usando l'estensione del MilvusClient
            $client = app(MilvusClient::class);
            $client->createPartition($partitionName);
            
            Log::info('milvus.partition.created', [
                'tenant_id' => $this->tenantId,
                'partition' => $partitionName,
                'method' => 'php_client',
            ]);
        } catch (\Throwable $e) {
            Log::warning('milvus.partition.php_failed', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            // Fallback: usa lo script Python esistente
            $this->createPartitionWithPython($collectionName, $partitionName);
        }
    }

    private function createPartitionWithPython(string $collectionName, string $partitionName): void
    {
        $scriptPath = base_path('create_milvus_partition.py');
        
        if (!file_exists($scriptPath)) {
            throw new \RuntimeException("Script Python non trovato: {$scriptPath}");
        }

        $command = [
            'python',
            $scriptPath,
            '--collection', $collectionName,
            '--partition', $partitionName,
        ];

        $result = Process::run($command);

        if ($result->failed()) {
            Log::error('milvus.partition.python_failed', [
                'tenant_id' => $this->tenantId,
                'command' => implode(' ', $command),
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
                'error' => $result->errorOutput(),
            ]);

            throw new \RuntimeException(
                "Fallimento creazione partizione Milvus per tenant {$this->tenantId}: " . 
                $result->errorOutput()
            );
        }

        Log::info('milvus.partition.created', [
            'tenant_id' => $this->tenantId,
            'partition' => $partitionName,
            'method' => 'python_script',
            'output' => $result->output(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('milvus.partition.job_failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
