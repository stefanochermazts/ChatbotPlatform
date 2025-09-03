<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Log;

class MilvusClientPython
{
    private string $collection;
    private string $pythonScript;

    public function __construct()
    {
        $cfg = config('rag.vector.milvus');
        $this->collection = (string) ($cfg['collection'] ?? 'kb_chunks_v1');
        $this->pythonScript = base_path('milvus_search.py');
        
        if (!file_exists($this->pythonScript)) {
            Log::error('milvus.python_script_not_found', ['script' => $this->pythonScript]);
            throw new \RuntimeException("Python script not found: {$this->pythonScript}");
        }
    }

    /**
     * 🐍 Esegue un'operazione Milvus tramite script Python
     */
    private function executePythonOperation(string $operation, array $params = []): array
    {
        try {
            // Prepara parametri per lo script Python
            $pythonParams = array_merge([
                'operation' => $operation,
                'collection' => $this->collection
            ], $params);
            
            $jsonParams = json_encode($pythonParams, JSON_UNESCAPED_UNICODE);
            $escapedParams = escapeshellarg($jsonParams);
            
            // Usa percorso completo a Python per evitare problemi di PATH su Windows
            $pythonPath = config('rag.vector.milvus.python_path', 'python');
            $command = "\"{$pythonPath}\" \"{$this->pythonScript}\" {$escapedParams} 2>&1";
            $output = shell_exec($command);
            
            if (empty($output)) {
                Log::error('milvus.python.no_output', ['command' => $command, 'operation' => $operation]);
                return ['success' => false, 'error' => 'No output from Python script'];
            }
            
            $result = json_decode(trim($output), true);
            
            if (!$result) {
                Log::error('milvus.python.invalid_json', [
                    'output' => $output,
                    'operation' => $operation
                ]);
                return ['success' => false, 'error' => 'Invalid JSON response from Python script'];
            }
            
            if (!$result['success']) {
                Log::warning('milvus.python.operation_failed', [
                    'operation' => $operation,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
            } else {
                Log::debug('milvus.python.operation_success', [
                    'operation' => $operation
                ]);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            Log::error('milvus.python.exception', [
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function upsertVectors(int $tenantId, int $documentId, array $chunks, array $vectors): void
    {
        $result = $this->executePythonOperation('upsert', [
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
            'vectors' => $vectors
        ]);
        
        if (!$result['success']) {
            Log::error('milvus.upsert_failed', [
                'tenant_id' => $tenantId,
                'document_id' => $documentId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        } else {
            Log::info('milvus.upsert_success', [
                'tenant_id' => $tenantId,
                'document_id' => $documentId,
                'inserted_count' => $result['inserted_count'] ?? 0
            ]);
        }
    }

    public function deleteByDocument(int $tenantId, int $documentId): void
    {
        // Deprecated in favore di deleteByPrimaryIds calcolati esternamente
    }

    public function searchTopK(int $tenantId, array $queryVector, int $k = 10): array
    {
        return $this->searchTopKWithEmbedding($tenantId, $queryVector, $k);
    }

    public function searchTopKWithEmbedding(int $tenantId, array $queryEmbedding, int $k = 10): array
    {
        $result = $this->executePythonOperation('search', [
            'tenant_id' => $tenantId,
            'query_vector' => array_map('floatval', $queryEmbedding),
            'limit' => max(1, $k)
        ]);
        
        if (!$result['success']) {
            Log::error('milvus.search_failed', [
                'tenant_id' => $tenantId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            return [];
        }
        
        // Converti formato Python in formato atteso da Laravel
        $hits = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $hits[] = [
                'primary_id' => (int) $hit['id'],
                'distance' => (float) $hit['distance'],
                'score' => (float) $hit['score']
            ];
        }
        
        return $hits;
    }

    public function health(): array
    {
        $result = $this->executePythonOperation('health');
        
        if (!$result['success']) {
            return [
                'connected' => false,
                'error' => $result['error'] ?? 'Health check failed'
            ];
        }
        
        return [
            'connected' => $result['connected'] ?? false,
            'collections' => $result['collections'] ?? [],
            'collection_exists' => $result['collection_exists'] ?? false,
            'collection_info' => $result['collection_info'] ?? []
        ];
    }

    public function deleteByPrimaryIds(array $primaryIds): void
    {
        if (empty($primaryIds)) {
            return;
        }
        
        $result = $this->executePythonOperation('delete_by_ids', [
            'primary_ids' => $primaryIds
        ]);
        
        if (!$result['success']) {
            Log::error('milvus.delete_by_ids_failed', [
                'primary_ids_count' => count($primaryIds),
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        } else {
            Log::info('milvus.delete_by_ids_success', [
                'deleted_count' => $result['deleted_count'] ?? 0
            ]);
        }
    }

    public function deleteByTenant(int $tenantId): void
    {
        $result = $this->executePythonOperation('delete_by_tenant', [
            'tenant_id' => $tenantId
        ]);
        
        if (!$result['success']) {
            Log::error('milvus.delete_by_tenant_failed', [
                'tenant_id' => $tenantId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        } else {
            Log::info('milvus.delete_by_tenant_success', [
                'tenant_id' => $tenantId
            ]);
        }
    }

    public function createPartition(string $partitionName): void
    {
        $result = $this->executePythonOperation('create_partition', [
            'partition_name' => $partitionName
        ]);
        
        if (!$result['success']) {
            Log::error('milvus.create_partition_failed', [
                'partition_name' => $partitionName,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
        } else {
            $created = $result['created'] ?? false;
            $alreadyExists = $result['already_exists'] ?? false;
            
            if ($created) {
                Log::info('milvus.partition_created', ['partition' => $partitionName]);
            } elseif ($alreadyExists) {
                Log::debug('milvus.partition_already_exists', ['partition' => $partitionName]);
            }
        }
    }

    public function hasPartition(string $partitionName): bool
    {
        $result = $this->executePythonOperation('has_partition', [
            'partition_name' => $partitionName
        ]);
        
        if (!$result['success']) {
            Log::warning('milvus.partition_check_failed', [
                'partition_name' => $partitionName,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            return false;
        }
        
        return $result['exists'] ?? false;
    }
}
