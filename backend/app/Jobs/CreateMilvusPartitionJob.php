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
        // Verifica se le partizioni Milvus sono abilitate
        if (!config('rag.vector.milvus.partitions_enabled', true)) {
            Log::info('milvus.partition.disabled', [
                'tenant_id' => $this->tenantId,
                'reason' => 'partitions_disabled_in_config',
            ]);
            return;
        }

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

            // Determina se siamo su Windows
            $isWindows = PHP_OS_FAMILY === 'Windows';
            
            if ($isWindows && $this->isPythonGrpcIssue($e)) {
                // Su Windows, se è un problema noto di grpcio, non provare Python
                Log::warning('milvus.partition.skipped_on_windows', [
                    'tenant_id' => $this->tenantId,
                    'reason' => 'grpcio_windows_compatibility_issue',
                    'error' => $e->getMessage(),
                    'solution' => 'Disable partitions in config or fix grpcio installation',
                ]);
                return;
            }

            // Su altri sistemi o per errori diversi, prova il fallback Python
            try {
                $this->createPartitionWithPython($collectionName, $partitionName);
            } catch (\Throwable $pythonError) {
                Log::error('milvus.partition.both_methods_failed', [
                    'tenant_id' => $this->tenantId,
                    'php_error' => $e->getMessage(),
                    'python_error' => $pythonError->getMessage(),
                ]);
                
                // Non rilancio l'eccezione per non bloccare la creazione del tenant
                // La partizione può essere creata manualmente se necessario
            }
        }
    }

    /**
     * Verifica se l'errore è dovuto a problemi noti di grpcio su Windows
     */
    private function isPythonGrpcIssue(\Throwable $e): bool
    {
        $message = $e->getMessage();
        
        // Controlla pattern tipici di errori grpcio su Windows
        $grpcioPatterns = [
            'WinError 10106',
            'Impossibile caricare o inizializzare il provider del servizio richiesto',
            'OSError.*provider del servizio',
            'grpc.*_cython.*cygrpc',
            'from grpc._cython import cygrpc',
            'asyncio.*windows_events',
            '_overlapped.*OSError',
        ];
        
        foreach ($grpcioPatterns as $pattern) {
            if (preg_match('/' . preg_quote($pattern, '/') . '/i', $message)) {
                return true;
            }
        }
        
        return false;
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
