<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantDocumentsController extends Controller
{
    public function index(Tenant $tenant)
    {
        $documents = Document::query()
            ->where('tenant_id', $tenant->id)
            ->latest()
            ->paginate(25);

        return view('admin.tenants.documents', [
            'tenant' => $tenant,
            'documents' => $documents,
        ]);
    }

    public function upload(Request $request, Tenant $tenant)
    {
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
                $path = $file->store("tenants/{$tenant->id}/uploads", 'public');

                $doc = Document::create([
                    'tenant_id' => $tenant->id,
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
        ]);
    }
}


