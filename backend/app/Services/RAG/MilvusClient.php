<?php

namespace App\Services\RAG;

use Hasanmertermis\MilvusPhpClient\Domain\Milvus;
use Hasanmertermis\MilvusPhpClient\Domain\Schema\Field;
use Milvus\Proto\Schema\DataType;

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
        if ($uri !== '') {
            $this->client->connectionByUri($uri, $token);
        } else {
            $this->client->connection($host, $port);
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
        }
    }

    public function deleteByDocument(int $tenantId, int $documentId): void
    {
        // L'SDK espone delete solo per primary key (id). Senza il numero di chunk non possiamo cancellare in massa.
        // No-op per ora; la cancellazione completa richiederebbe mantenere il numero di chunk per documento.
    }

    public function searchTopK(int $tenantId, array $queryVector, int $k = 10): array
    {
        $field = (new Field())
            ->setFieldData(array_map('floatval', $queryVector))
            ->setMetricType($this->metric)
            ->setFieldName('vector');

        $result = $this->client->search($field, $this->collection, max(1, $k), 1000);

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
}
