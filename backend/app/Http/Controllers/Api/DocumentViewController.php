<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DocumentViewController extends Controller
{
    /**
     * Generate secure view token for a document
     */
    public function generateViewToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_id' => 'required|integer|exists:documents,id',
            'chunk_position' => 'nullable|integer|min:0',
            'highlight_text' => 'nullable|string|max:500',
            'expires_in' => 'nullable|integer|min:300|max:86400', // 5 min to 24 hours
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $validator->errors()
            ], 400);
        }

        $tenantId = (int) $request->attributes->get('tenant_id');
        $documentId = $validator->validated()['document_id'];

        // Verify document belongs to tenant
        $document = Document::where('id', $documentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found or access denied'
            ], 404);
        }

        try {
            $expiresIn = $validator->validated()['expires_in'] ?? 3600; // Default 1 hour
            $token = $this->createSecureToken($document, $validator->validated(), $expiresIn);

            $viewUrl = route('api.document.view', ['token' => $token]);

            return response()->json([
                'success' => true,
                'view_token' => $token,
                'view_url' => $viewUrl,
                'expires_at' => now()->addSeconds($expiresIn)->toISOString(),
                'document' => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'type' => $document->file_type,
                    'size' => $document->file_size,
                ]
            ]);

        } catch (\Throwable $e) {
            Log::error('document.view_token_generation_failed', [
                'document_id' => $documentId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate view token'
            ], 500);
        }
    }

    /**
     * View document with secure token
     */
    public function viewDocument(Request $request, string $token): Response
    {
        try {
            $tokenData = $this->validateAndDecodeToken($token);

            if (!$tokenData) {
                return $this->errorResponse('Invalid or expired token', 403);
            }

            $document = Document::find($tokenData['document_id']);

            if (!$document) {
                return $this->errorResponse('Document not found', 404);
            }

            // Check if file exists in storage
            if (!Storage::exists($document->storage_path)) {
                return $this->errorResponse('Document file not found', 404);
            }

            // Log access for audit
            Log::info('document.viewed', [
                'document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'title' => $document->title,
                'chunk_position' => $tokenData['chunk_position'] ?? null,
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ]);

            // Get file content
            $content = Storage::get($document->storage_path);
            $mimeType = $this->getMimeType($document->file_type);

            // For text-based documents, apply highlighting if requested
            if ($this->isTextDocument($document->file_type) && !empty($tokenData['highlight_text'])) {
                $content = $this->highlightText($content, $tokenData['highlight_text']);
                $mimeType = 'text/html'; // Convert to HTML for highlighting
            }

            // Set appropriate headers
            $headers = [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $document->title . '"',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];

            return response($content, 200, $headers);

        } catch (\Throwable $e) {
            Log::error('document.view_failed', [
                'token' => $token,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Document viewing failed', 500);
        }
    }

    /**
     * Get document metadata for citation display
     */
    public function getDocumentInfo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_ids' => 'required|array|max:50',
            'document_ids.*' => 'integer|exists:documents,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid document IDs',
                'errors' => $validator->errors()
            ], 400);
        }

        $tenantId = (int) $request->attributes->get('tenant_id');
        $documentIds = $validator->validated()['document_ids'];

        try {
            $documents = Document::whereIn('id', $documentIds)
                ->where('tenant_id', $tenantId)
                ->select(['id', 'title', 'file_type', 'file_size', 'created_at'])
                ->get()
                ->keyBy('id');

            $result = [];
            foreach ($documentIds as $docId) {
                if (isset($documents[$docId])) {
                    $doc = $documents[$docId];
                    $result[$docId] = [
                        'id' => $doc->id,
                        'title' => $doc->title,
                        'type' => $doc->file_type,
                        'size' => $this->formatFileSize($doc->file_size),
                        'icon' => $this->getFileIcon($doc->file_type),
                        'created_at' => $doc->created_at->format('d/m/Y'),
                    ];
                } else {
                    $result[$docId] = null; // Document not found or no access
                }
            }

            return response()->json([
                'success' => true,
                'documents' => $result,
            ]);

        } catch (\Throwable $e) {
            Log::error('document.info_fetch_failed', [
                'document_ids' => $documentIds,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch document information'
            ], 500);
        }
    }

    /**
     * Create secure token for document access
     */
    private function createSecureToken(Document $document, array $params, int $expiresIn): string
    {
        $tokenData = [
            'document_id' => $document->id,
            'tenant_id' => $document->tenant_id,
            'chunk_position' => $params['chunk_position'] ?? null,
            'highlight_text' => $params['highlight_text'] ?? null,
            'issued_at' => time(),
            'expires_at' => time() + $expiresIn,
        ];

        $token = Str::random(32);
        $cacheKey = "document_view_token:{$token}";

        // Store token in cache with expiration
        Cache::put($cacheKey, $tokenData, $expiresIn);

        return $token;
    }

    /**
     * Validate and decode secure token
     */
    private function validateAndDecodeToken(string $token): ?array
    {
        $cacheKey = "document_view_token:{$token}";
        $tokenData = Cache::get($cacheKey);

        if (!$tokenData) {
            return null; // Token not found or expired
        }

        // Verify token hasn't expired (double check)
        if ($tokenData['expires_at'] < time()) {
            Cache::forget($cacheKey);
            return null;
        }

        return $tokenData;
    }

    /**
     * Get MIME type for file type
     */
    private function getMimeType(string $fileType): string
    {
        return match (strtolower($fileType)) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt' => 'application/vnd.ms-powerpoint',
            'html', 'htm' => 'text/html',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * Check if document is text-based for highlighting
     */
    private function isTextDocument(string $fileType): bool
    {
        return in_array(strtolower($fileType), ['txt', 'md', 'html', 'htm', 'csv', 'json', 'xml']);
    }

    /**
     * Highlight text in content
     */
    private function highlightText(string $content, string $highlightText): string
    {
        if (empty($highlightText)) {
            return $content;
        }

        // Escape HTML entities in content
        $content = htmlspecialchars($content);

        // Highlight the text
        $highlightedText = htmlspecialchars($highlightText);
        $highlighted = "<mark style='background-color: #ffeb3b; padding: 0 2px;'>{$highlightedText}</mark>";

        // Case-insensitive replace
        $content = str_ireplace($highlightText, $highlighted, $content);

        // Wrap in basic HTML
        return "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Document Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
        mark { background-color: #ffeb3b; padding: 0 2px; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <pre>{$content}</pre>
</body>
</html>";
    }

    /**
     * Get file icon class for file type
     */
    private function getFileIcon(string $fileType): string
    {
        return match (strtolower($fileType)) {
            'pdf' => '📄',
            'txt', 'md' => '📝',
            'docx', 'doc' => '📄',
            'xlsx', 'xls' => '📊',
            'pptx', 'ppt' => '📊',
            'html', 'htm' => '🌐',
            'csv' => '📊',
            'json' => '📋',
            'xml' => '📋',
            default => '📁',
        };
    }

    /**
     * Format file size for display
     */
    private function formatFileSize(?int $bytes): string
    {
        if (!$bytes) return 'N/A';

        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.1f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Create error response
     */
    private function errorResponse(string $message, int $status): Response
    {
        $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Error</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
        .error { color: #d32f2f; font-size: 18px; }
    </style>
</head>
<body>
    <div class='error'>
        <h2>Error {$status}</h2>
        <p>{$message}</p>
    </div>
</body>
</html>";

        return response($html, $status, ['Content-Type' => 'text/html']);
    }
}