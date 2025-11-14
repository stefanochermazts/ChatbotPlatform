<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Contracts\Ingestion\VectorIndexingServiceInterface;
use App\Exceptions\IndexingException;
use App\Services\RAG\MilvusClient;
use Illuminate\Support\Facades\Log;

/**
 * Service for vector database indexing with Milvus
 *
 * Wraps MilvusClient with additional features:
 * - Error handling
 * - Logging
 * - Tenant partition management
 */
class VectorIndexingService implements VectorIndexingServiceInterface
{
    public function __construct(
        private readonly MilvusClient $milvusClient
    ) {}

    /**
     * {@inheritDoc}
     */
    public function upsert(int $documentId, int $tenantId, array $chunks): bool
    {
        if (empty($chunks)) {
            Log::warning('indexing.no_chunks', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
            ]);

            return true; // No chunks to index is not an error
        }

        Log::debug('indexing.upsert_start', [
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
            'chunks_count' => count($chunks),
        ]);

        try {
            // Prepare data for Milvus
            // MilvusClient expects $chunks (text array) and $vectors (vector array) separately
            $chunkTexts = [];
            $vectors = [];

            foreach ($chunks as $chunk) {
                if (! isset($chunk['vector'])) {
                    Log::warning('indexing.invalid_chunk_missing_vector', [
                        'document_id' => $documentId,
                        'chunk' => $chunk,
                    ]);

                    continue;
                }

                $chunkTexts[] = $chunk['text'] ?? '';
                $vectors[] = $chunk['vector'];
            }

            if (empty($vectors)) {
                throw new IndexingException('No valid vectors to index');
            }

            // Upsert to Milvus (void return type)
            $this->milvusClient->upsertVectors(
                $tenantId,
                $documentId,
                $chunkTexts,
                $vectors
            );

            Log::debug('indexing.upsert_success', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
                'vectors_count' => count($vectors),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('indexing.upsert_failed', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new IndexingException(
                "Failed to index vectors for document {$documentId}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $documentId, int $tenantId): bool
    {
        Log::debug('indexing.delete_start', [
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
        ]);

        try {
            // MilvusClient::deleteByDocument() returns void
            $this->milvusClient->deleteByDocument($tenantId, $documentId);

            Log::debug('indexing.delete_success', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('indexing.delete_failed', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            throw new IndexingException(
                "Failed to delete vectors for document {$documentId}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByTenant(int $tenantId): bool
    {
        Log::warning('indexing.delete_tenant_start', [
            'tenant_id' => $tenantId,
            'note' => 'This operation will delete ALL vectors for the tenant!',
        ]);

        try {
            // Delete all vectors for the tenant
            $result = $this->milvusClient->deleteByTenant($tenantId);

            Log::warning('indexing.delete_tenant_success', [
                'tenant_id' => $tenantId,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::error('indexing.delete_tenant_failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            throw new IndexingException(
                "Failed to delete tenant vectors for tenant {$tenantId}: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function exists(int $documentId, int $tenantId): bool
    {
        // TODO: Implement existence check when needed
        // For now, return false as placeholder
        // MilvusClient doesn't have a direct exists() method
        Log::debug('indexing.exists_check_placeholder', [
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
            'note' => 'Method not yet implemented',
        ]);

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getCollectionName(int $tenantId): string
    {
        // Collection naming convention for tenant partitions
        // TODO: Verify this matches actual Milvus setup
        return "tenant_{$tenantId}_vectors";
    }
}
