<?php

declare(strict_types=1);

namespace App\Contracts\Document;

use App\Models\Document;

/**
 * Interface for document storage cleanup service
 * 
 * Responsible for cleaning up storage (S3, Milvus, database)
 * when documents are deleted.
 * 
 * @package App\Contracts\Document
 */
interface DocumentStorageServiceInterface
{
    /**
     * Delete document file from S3/Azure Blob
     * 
     * @param string $filePath Storage path
     * @return bool True if deletion successful
     * @throws \App\Exceptions\StorageException If deletion fails
     */
    public function deleteFile(string $filePath): bool;

    /**
     * Delete document vectors from Milvus
     * 
     * Removes all chunk vectors associated with the document.
     * 
     * @param int $documentId Document ID
     * @param int $tenantId Tenant ID
     * @return bool True if deletion successful
     * @throws \App\Exceptions\IndexingException If deletion fails
     */
    public function deleteVectors(int $documentId, int $tenantId): bool;

    /**
     * Delete document chunks from database
     * 
     * @param int $documentId Document ID
     * @return bool True if deletion successful
     */
    public function deleteChunks(int $documentId): bool;

    /**
     * Complete cleanup for a document
     * 
     * Deletes:
     * 1. File from S3
     * 2. Vectors from Milvus
     * 3. Chunks from database
     * 4. Document record (soft delete)
     * 
     * @param Document $document Document to cleanup
     * @return bool True if all cleanup successful
     */
    public function cleanup(Document $document): bool;

    /**
     * Bulk cleanup for multiple documents
     * 
     * @param array<int> $documentIds Array of document IDs
     * @param int $tenantId Tenant ID
     * @return array{success: int, failed: int, errors: array<string>} Cleanup results
     */
    public function bulkCleanup(array $documentIds, int $tenantId): array;

    /**
     * Delete all storage for a tenant (USE WITH EXTREME CAUTION!)
     * 
     * Deletes:
     * - All files in tenant's S3 folder
     * - All vectors in tenant's Milvus partition
     * - All chunks for tenant's documents
     * 
     * @param int $tenantId Tenant ID
     * @return bool True if cleanup successful
     */
    public function cleanupTenant(int $tenantId): bool;

    /**
     * Check if file exists in storage
     * 
     * @param string $filePath Storage path
     * @return bool True if file exists
     */
    public function fileExists(string $filePath): bool;

    /**
     * Get file size
     * 
     * @param string $filePath Storage path
     * @return int|null File size in bytes, null if file doesn't exist
     */
    public function getFileSize(string $filePath): ?int;
}

