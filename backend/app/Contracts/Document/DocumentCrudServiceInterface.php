<?php

declare(strict_types=1);

namespace App\Contracts\Document;

use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;

/**
 * Interface for document CRUD operations service
 *
 * Responsible for creating, reading, updating, and deleting documents
 * with proper tenant scoping and authorization.
 */
interface DocumentCrudServiceInterface
{
    /**
     * Create a new document
     *
     * @param  array{name: string, knowledge_base_id: int, path?: string, source_url?: string, mime_type?: string}  $data  Document data
     * @param  int  $tenantId  Tenant ID (multitenancy scoping)
     * @return Document Created document model
     *
     * @throws \Illuminate\Validation\ValidationException If data is invalid
     * @throws \Illuminate\Auth\Access\AuthorizationException If user cannot create
     */
    public function create(array $data, int $tenantId): Document;

    /**
     * Find document by ID with tenant scoping
     *
     * @param  int  $documentId  Document ID
     * @param  int  $tenantId  Tenant ID
     * @return Document Document model
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If not found
     */
    public function findById(int $documentId, int $tenantId): Document;

    /**
     * Update a document
     *
     * @param  int  $documentId  Document ID
     * @param  array<string, mixed>  $data  Updated data
     * @param  int  $tenantId  Tenant ID
     * @return Document Updated document model
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If not found
     * @throws \Illuminate\Auth\Access\AuthorizationException If user cannot update
     */
    public function update(int $documentId, array $data, int $tenantId): Document;

    /**
     * Soft delete a document
     *
     * Marks document as deleted without removing from database.
     * Also triggers cleanup of chunks and vectors.
     *
     * @param  int  $documentId  Document ID
     * @param  int  $tenantId  Tenant ID
     * @return bool True if deletion successful
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If not found
     * @throws \Illuminate\Auth\Access\AuthorizationException If user cannot delete
     */
    public function delete(int $documentId, int $tenantId): bool;

    /**
     * Bulk delete documents
     *
     * Soft deletes multiple documents at once.
     *
     * @param  array<int>  $documentIds  Array of document IDs
     * @param  int  $tenantId  Tenant ID
     * @return int Number of documents deleted
     */
    public function bulkDelete(array $documentIds, int $tenantId): int;

    /**
     * Restore a soft-deleted document
     *
     * @param  int  $documentId  Document ID
     * @param  int  $tenantId  Tenant ID
     * @return Document Restored document model
     */
    public function restore(int $documentId, int $tenantId): Document;

    /**
     * Get all documents for a knowledge base
     *
     * @param  int  $knowledgeBaseId  Knowledge base ID
     * @param  int  $tenantId  Tenant ID
     * @return Collection<int, Document> Collection of documents
     */
    public function getByKnowledgeBase(int $knowledgeBaseId, int $tenantId): Collection;
}
