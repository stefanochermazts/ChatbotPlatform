<?php

declare(strict_types=1);

namespace App\Contracts\Ingestion;

use App\Exceptions\IndexingException;

/**
 * Interface for vector database indexing service
 *
 * Responsible for upserting and managing vectors in Milvus vector database.
 * Handles tenant partitioning and vector operations.
 */
interface VectorIndexingServiceInterface
{
    /**
     * Upsert document chunks with embeddings to Milvus
     *
     * Inserts or updates vectors in the tenant-specific partition.
     * Uses document_id as primary key for idempotent operations.
     *
     * @param  int  $documentId  Document ID (primary key)
     * @param  int  $tenantId  Tenant ID (for partition scoping)
     * @param  array<int, array{chunk_id: int, text: string, vector: array<int, float>, metadata: array<string, mixed>}>  $chunks  Chunks with embeddings
     * @return bool True if upsert successful
     *
     * @throws IndexingException If Milvus operation fails
     */
    public function upsert(int $documentId, int $tenantId, array $chunks): bool;

    /**
     * Delete all vectors for a document
     *
     * Removes all chunk vectors associated with a document ID.
     *
     * @param  int  $documentId  Document ID
     * @param  int  $tenantId  Tenant ID (for partition scoping)
     * @return bool True if deletion successful
     *
     * @throws IndexingException If Milvus operation fails
     */
    public function delete(int $documentId, int $tenantId): bool;

    /**
     * Delete all vectors for a tenant
     *
     * Drops the entire tenant partition. USE WITH CAUTION!
     *
     * @param  int  $tenantId  Tenant ID
     * @return bool True if deletion successful
     *
     * @throws IndexingException If Milvus operation fails
     */
    public function deleteByTenant(int $tenantId): bool;

    /**
     * Check if vectors exist for a document
     *
     * @param  int  $documentId  Document ID
     * @param  int  $tenantId  Tenant ID
     * @return bool True if vectors exist
     */
    public function exists(int $documentId, int $tenantId): bool;

    /**
     * Get collection name for tenant partition
     *
     * @param  int  $tenantId  Tenant ID
     * @return string Collection name
     */
    public function getCollectionName(int $tenantId): string;
}
