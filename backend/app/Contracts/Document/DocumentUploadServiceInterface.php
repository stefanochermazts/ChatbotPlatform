<?php

declare(strict_types=1);

namespace App\Contracts\Document;

use App\Models\Document;
use Illuminate\Http\UploadedFile;

/**
 * Interface for document upload service
 *
 * Responsible for handling file uploads, validation, virus scanning,
 * and dispatching ingestion jobs.
 */
interface DocumentUploadServiceInterface
{
    /**
     * Upload and process a document file
     *
     * Steps:
     * 1. Validate file (size, type, virus scan)
     * 2. Store file in S3/Azure Blob
     * 3. Create document record
     * 4. Dispatch ingestion job
     *
     * @param  UploadedFile  $file  Uploaded file
     * @param  int  $knowledgeBaseId  Knowledge base ID
     * @param  int  $tenantId  Tenant ID
     * @param  array<string, mixed>  $metadata  Optional metadata
     * @return Document Created document model
     *
     * @throws \Illuminate\Validation\ValidationException If file is invalid
     * @throws \App\Exceptions\UploadException If upload fails
     */
    public function upload(UploadedFile $file, int $knowledgeBaseId, int $tenantId, array $metadata = []): Document;

    /**
     * Validate uploaded file
     *
     * Checks:
     * - File size (max 50MB)
     * - MIME type (whitelist)
     * - File extension
     * - Virus scan (if enabled)
     *
     * @param  UploadedFile  $file  File to validate
     * @return bool True if valid
     *
     * @throws \Illuminate\Validation\ValidationException If validation fails
     */
    public function validate(UploadedFile $file): bool;

    /**
     * Store file to cloud storage
     *
     * @param  UploadedFile  $file  File to store
     * @param  int  $tenantId  Tenant ID (for path prefix)
     * @return string Storage path (e.g., "tenant-5/documents/abc123.pdf")
     *
     * @throws \App\Exceptions\StorageException If storage fails
     */
    public function store(UploadedFile $file, int $tenantId): string;

    /**
     * Dispatch ingestion job for document
     *
     * @param  Document  $document  Document to ingest
     */
    public function dispatchIngestionJob(Document $document): void;

    /**
     * Scan file for viruses (if virus scanner is configured)
     *
     * @param  UploadedFile  $file  File to scan
     * @return bool True if file is clean
     *
     * @throws \App\Exceptions\VirusDetectedException If virus found
     */
    public function scanForViruses(UploadedFile $file): bool;

    /**
     * Get supported file extensions
     *
     * @return array<string> Array of extensions (pdf, docx, txt, etc.)
     */
    public function getSupportedExtensions(): array;

    /**
     * Get supported MIME types
     *
     * @return array<string> Array of MIME types
     */
    public function getSupportedMimeTypes(): array;
}
