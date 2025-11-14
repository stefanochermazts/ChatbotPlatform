<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $docs = Document::query()->where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json($docs);
    }

    public function store(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file'],
            'metadata' => ['nullable', 'array'],
        ]);

        $path = $request->file('file')->store("tenants/{$tenantId}/uploads", 'public');

        $doc = Document::create([
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'source' => 'upload',
            'path' => $path,
            'metadata' => $validated['metadata'] ?? null,
            'ingestion_status' => 'pending',
        ]);

        IngestUploadedDocumentJob::dispatch($doc->id);

        return response()->json($doc, 201);
    }

    public function storeBatch(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file'],
        ]);

        $maxFiles = 200;
        $files = $request->file('files', []);
        if (count($files) > $maxFiles) {
            return response()->json(['message' => 'Too many files. Max '.$maxFiles], 422);
        }

        $created = [];
        $errors = [];
        foreach ($files as $index => $file) {
            try {
                $original = (string) $file->getClientOriginalName();
                $title = pathinfo($original, PATHINFO_FILENAME) ?: 'Documento';
                $path = $file->store("tenants/{$tenantId}/uploads", 'public');

                $doc = Document::create([
                    'tenant_id' => $tenantId,
                    'title' => $title,
                    'source' => 'upload',
                    'path' => $path,
                    'metadata' => null,
                    'ingestion_status' => 'pending',
                ]);

                IngestUploadedDocumentJob::dispatch($doc->id);
                $created[] = $doc;
            } catch (\Throwable $e) {
                $errors[] = [
                    'index' => $index,
                    'name' => isset($original) ? $original : null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'created_count' => count($created),
            'errors_count' => count($errors),
            'created' => $created,
            'errors' => $errors,
        ], 207);
    }

    public function destroy(Request $request, Document $document)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        abort_unless($document->tenant_id === $tenantId, 404);

        // opzionale: delete file
        if (Storage::disk('public')->exists($document->path)) {
            Storage::disk('public')->delete($document->path);
        }

        $document->delete();

        return response()->json(['status' => 'deleted']);
    }
}
