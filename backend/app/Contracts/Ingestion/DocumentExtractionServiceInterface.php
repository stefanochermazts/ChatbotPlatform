<?php

declare(strict_types=1);

namespace App\Contracts\Ingestion;

use App\Exceptions\ExtractionException;

/**
 * Interface for document text extraction service
 * 
 * Responsible for extracting raw text from various document formats
 * (PDF, DOCX, XLSX, PPTX, TXT, Markdown).
 * 
 * @package App\Contracts\Ingestion
 */
interface DocumentExtractionServiceInterface
{
    /**
     * Extract text content from a document file
     * 
     * Supports multiple formats: PDF, DOCX, XLSX, PPTX, TXT, Markdown.
     * The method automatically detects the format and uses the appropriate
     * extraction strategy.
     * 
     * @param string $filePath Storage path to document (e.g., "documents/abc123.pdf")
     * @return string Extracted raw text content
     * @throws ExtractionException If file cannot be read or format is unsupported
     */
    public function extractText(string $filePath): string;

    /**
     * Detect document format from file extension and/or mime type
     * 
     * @param string $filePath Storage path to document
     * @return string Format identifier (pdf, docx, xlsx, pptx, txt, md)
     * @throws ExtractionException If format cannot be determined
     */
    public function detectFormat(string $filePath): string;

    /**
     * Check if a document format is supported
     * 
     * @param string $format Format identifier
     * @return bool True if format is supported
     */
    public function isFormatSupported(string $format): bool;
}

