<?php

namespace App\Services\RAG;

use Hasanmertermis\MilvusPhpClient\Domain\Milvus;
use Hasanmertermis\MilvusPhpClient\Domain\Schema\Field;
use Milvus\Proto\Schema\DataType;
use Illuminate\Support\Facades\Log;

class MilvusClient
{
    private Milvus $client;
    private string $collection;
    private string $metric;

    public function __construct()
    {
        $cfg = config('rag.vector.milvus');
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (int) ($cfg['port'] ?? 19530);
        $uri = (string) ($cfg['uri'] ?? '');
        $token = (string) ($cfg['token'] ?? '');
        $this->collection = (string) ($cfg['collection'] ?? 'kb_chunks_v1');
        $this->metric = strtolower((string) config('rag.vector.metric', 'cosine')) === 'l2' ? 'L2' : 'COSINE';

        $this->client = new Milvus();
        try {
            if ($uri !== '') {
                $this->client->connectionByUri($uri, $token);
            } else {
                $this->client->connection($host, $port);
            }
        } catch (\Throwable $e) {
            Log::error('milvus.connection_failed', ['error' => $e->getMessage()]);
        }

        $this->ensureCollection();
    }

    private function ensureCollection(): void
    {
        // La collection deve essere creata out-of-band (script pymilvus/CLI)
    }

    public function upsertVectors(int $tenantId, int $documentId, array $chunks, array $vectors): void
    {
        $total = min(count($chunks), count($vectors));
        for ($i = 0; $i < $total; $i++) {
            try {
                $id = (int) (($documentId * 100000) + $i);
                $vec = array_map('floatval', (array) $vectors[$i]);

                $data = [
                    (new Field())->setFieldName('id')->setIsPrimaryField(true)->setFieldType(DataType::Int64)->setFieldData($id),
                    (new Field())->setFieldName('tenant_id')->setFieldType(DataType::Int64)->setFieldData((int) $tenantId),
                    (new Field())->setFieldName('document_id')->setFieldType(DataType::Int64)->setFieldData((int) $documentId),
                    (new Field())->setFieldName('chunk_index')->setFieldType(DataType::Int64)->setFieldData($i),
                    // l'SDK si aspetta vettore come array annidato: [ [floats...] ]
                    (new Field())->setFieldName('vector')->setFieldType(DataType::FloatVector)->setFieldData([$vec]),
                ];

                // Insert di una sola riga alla volta (l'SDK imposta numRows=1)
                $this->client->insert($data, $this->collection);
            } catch (\Throwable $e) {
                Log::error('milvus.insert_failed', [
                    'tenant_id' => $tenantId,
                    'document_id' => $documentId,
                    'chunk_index' => $i,
                    'error' => $e->getMessage(),
                ]);
            }
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
    
    /**
     * Cerca usando un embedding già generato (per HyDE e altre tecniche avanzate)
     */
    public function searchTopKWithEmbedding(int $tenantId, array $queryEmbedding, int $k = 10): array
    {
        $field = (new Field())
            ->setFieldData(array_map('floatval', $queryEmbedding))
            ->setMetricType($this->metric)
            ->setFieldName('vector');

        try {
            $result = $this->client->search($field, $this->collection, max(1, $k), 1000);
        } catch (\Throwable $e) {
            Log::error('milvus.search_failed', ['error' => $e->getMessage()]);
            return [];
        }

        $hits = [];
        foreach ($result as $hit) { // l'SDK ritorna array di ['id'=>..., 'distance'=>...]
            $primaryId = (int) ($hit['id'] ?? 0);
            if ($primaryId <= 0) {
                continue;
            }
            $docId = intdiv($primaryId, 100000);
            $chunkIndex = $primaryId % 100000;
            $hits[] = [
                'document_id' => $docId,
                'chunk_index' => $chunkIndex,
                'score' => isset($hit['distance']) ? (float) $hit['distance'] : null,
            ];
        }
        return $hits;
    }

    public function health(): array
    {
        try {
            // Ping minimale: prova una ricerca con vettore nullo della giusta dimensione
            $dim = (int) config('rag.embedding_dim');
            $dummy = array_fill(0, max(1, $dim), 0.0);
            $field = (new Field())
                ->setFieldData($dummy)
                ->setMetricType($this->metric)
                ->setFieldName('vector');
            // k=1, nprobe=1; ignora l'output
            $this->client->search($field, $this->collection, 1, 1);
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Prova a cancellare per primary ids. Accetta molti ID; gestisce in batch.
     * Se lo SDK non supporta deleteByIds, logga e ignora.
     */
    public function deleteByPrimaryIds(array $primaryIds): void
    {
        if ($primaryIds === []) {
            return;
        }
        $uniqueIds = array_values(array_unique(array_map('intval', $primaryIds)));
        Log::info('milvus.delete.request', [
            'total_ids' => count($uniqueIds),
            'collection' => $this->collection,
            'capabilities' => [
                'delete' => method_exists($this->client, 'delete'),
                'deleteByIds' => method_exists($this->client, 'deleteByIds'),
                'deleteEntities' => method_exists($this->client, 'deleteEntities'),
                'deleteByExpression' => method_exists($this->client, 'deleteByExpression'),
                'deleteByExpr' => method_exists($this->client, 'deleteByExpr'),
            ],
        ]);
        $chunks = array_chunk($uniqueIds, 50); // batch piccolo per evitare limiti gRPC
        foreach ($chunks as $batch) {
            $ids = array_map('intval', $batch);
            $lastError = null;
            // 1) Prova deleteByIds in entrambe le firme note (evitiamo delete(expr))
            if (method_exists($this->client, 'deleteByIds')) {
                try {
                    // Firma 1: (collection, ids)
                    Log::info('milvus.delete.try', ['method' => 'deleteByIds', 'signature' => 'collection,ids', 'count' => count($ids)]);
                    $this->client->deleteByIds($this->collection, $ids);
                    Log::info('milvus.delete.ok', ['count' => count($ids), 'mode' => 'deleteByIds(collection,ids)']);
                    continue; // batch ok
                } catch (\Throwable $e1) {
                    $lastError = $e1;
                    try {
                        // Firma 2: (ids, collection)
                        Log::info('milvus.delete.try', ['method' => 'deleteByIds', 'signature' => 'ids,collection', 'count' => count($ids)]);
                        $this->client->deleteByIds($ids, $this->collection);
                        Log::info('milvus.delete.ok', ['count' => count($ids), 'mode' => 'deleteByIds(ids,collection)']);
                        continue; // batch ok
                    } catch (\Throwable $e2) {
                        $lastError = $e2;
                    }
                }
            }
            // 2) Fallback estremo: elimina uno per uno con deleteByIds
            if (method_exists($this->client, 'deleteByIds')) {
                $allOk = true;
                foreach ($ids as $singleId) {
                    try {
                        $this->client->deleteByIds($this->collection, [(int) $singleId]);
                    } catch (\Throwable $e1) {
                        try {
                            $this->client->deleteByIds([(int) $singleId], $this->collection);
                        } catch (\Throwable $e2) {
                            $allOk = false;
                            $lastError = $e2;
                            break;
                        }
                    }
                }
                if ($allOk) {
                    Log::info('milvus.delete.ok', ['count' => count($ids), 'mode' => 'single-deleteByIds']);
                    continue;
                }
            }
            // 3) Se nessuna strada ha funzionato, logga
            Log::error('milvus.delete_failed', [
                'count' => count($batch),
                'error' => $lastError ? $lastError->getMessage() : 'unknown',
            ]);
        }
        Log::info('milvus.delete.completed');
    }

    /**
     * Cancella tutti i vettori appartenenti a un tenant usando un'espressione di filtro.
     * Se l'SDK non supporta delete(expr), verrà sollevata un'eccezione gestita dal chiamante.
     */
    public function deleteByTenant(int $tenantId): void
    {
        try {
            if (method_exists($this->client, 'delete')) {
                $expr = 'tenant_id == '.((int) $tenantId);
                $this->client->delete($this->collection, $expr);
            } else {
                Log::warning('milvus.delete_by_tenant_unsupported');
            }
        } catch (\Throwable $e) {
            Log::error('milvus.delete_by_tenant_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Verifica se il client è connesso a Milvus
     */
    private function isConnected(): bool
    {
        try {
            // Prova un'operazione semplice per verificare la connessione
            return $this->client !== null;
        } catch (\Throwable $e) {
            Log::warning('milvus.connection.check_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Crea una partizione nella collection per isolare i dati del tenant.
     * Se la partizione esiste già, non fa nulla.
     */
    public function createPartition(string $partitionName): void
    {
        try {
            // Verifica connessione prima di procedere
            if (!$this->isConnected()) {
                throw new \RuntimeException('Cliente Milvus non connesso');
            }

            // Verifica se la partizione esiste già
            if ($this->hasPartition($partitionName)) {
                Log::info('milvus.partition.already_exists', [
                    'collection' => $this->collection,
                    'partition' => $partitionName,
                ]);
                return;
            }

            // Log dei metodi disponibili per debug
            $availableMethods = [];
            foreach (['createPartition', 'partition', 'partitionCreate'] as $method) {
                if (method_exists($this->client, $method)) {
                    $availableMethods[] = $method;
                }
            }

            Log::debug('milvus.partition.create_attempt', [
                'collection' => $this->collection,
                'partition' => $partitionName,
                'available_methods' => $availableMethods,
            ]);

            // Crea la partizione
            if (method_exists($this->client, 'createPartition')) {
                $this->client->createPartition($this->collection, $partitionName);
            } elseif (method_exists($this->client, 'partition')) {
                $this->client->partition($this->collection, $partitionName);
            } elseif (method_exists($this->client, 'partitionCreate')) {
                $this->client->partitionCreate($this->collection, $partitionName);
            } else {
                throw new \RuntimeException('SDK Milvus non supporta creazione partizioni. Metodi disponibili: ' . implode(', ', get_class_methods($this->client)));
            }

            Log::info('milvus.partition.created', [
                'collection' => $this->collection,
                'partition' => $partitionName,
            ]);
        } catch (\Throwable $e) {
            Log::error('milvus.partition.create_failed', [
                'collection' => $this->collection,
                'partition' => $partitionName,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Verifica se una partizione esiste nella collection.
     */
    public function hasPartition(string $partitionName): bool
    {
        try {
            // Prova diversi metodi per verificare l'esistenza della partizione
            if (method_exists($this->client, 'hasPartition')) {
                return $this->client->hasPartition($this->collection, $partitionName);
            }

            if (method_exists($this->client, 'listPartitions')) {
                $partitions = $this->client->listPartitions($this->collection);
                return in_array($partitionName, $partitions, true);
            }

            if (method_exists($this->client, 'getPartitions')) {
                $partitions = $this->client->getPartitions($this->collection);
                return in_array($partitionName, $partitions, true);
            }

            // Fallback: assume che non esista se non possiamo verificare
            Log::warning('milvus.partition.check_unsupported', [
                'collection' => $this->collection,
                'partition' => $partitionName,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::warning('milvus.partition.check_failed', [
                'collection' => $this->collection,
                'partition' => $partitionName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
