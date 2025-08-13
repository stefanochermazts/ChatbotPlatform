<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestUploadedDocumentJob;
use App\Jobs\DeleteVectorsJob;
use App\Models\Document;
use App\Models\Tenant;
use App\Services\RAG\MilvusClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentAdminController extends Controller
{
    public function index(Tenant $tenant)
    {
        $docs = Document::where('tenant_id', $tenant->id)->orderByDesc('id')->paginate(20);
        return view('admin.documents.index', compact('tenant', 'docs'));
    }

    public function upload(Request $request, Tenant $tenant)
    {
        // Se presente input multiplo `files[]`, gestiamo batch upload
        if ($request->hasFile('files')) {
            $request->validate([
                'files' => ['required', 'array', 'min:1'],
                'files.*' => ['file', 'mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx'],
            ]);

            $created = 0;
            $errors = 0;
            foreach ($request->file('files', []) as $file) {
                try {
                    $original = (string) $file->getClientOriginalName();
                    $title = pathinfo($original, PATHINFO_FILENAME) ?: 'Documento';
                    $path = $file->store('kb/'.$tenant->id, 'public');

                    $doc = Document::create([
                        'tenant_id' => $tenant->id,
                        'title' => $title,
                        'source' => 'upload',
                        'path' => $path,
                        'ingestion_status' => 'pending',
                    ]);
                    IngestUploadedDocumentJob::dispatch($doc->id)->onQueue('ingestion');
                    $created++;
                } catch (\Throwable) {
                    $errors++;
                }
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'created_count' => $created,
                    'errors_count' => $errors,
                ]);
            }

            return back()->with('ok', "Upload completato: {$created} creati, {$errors} errori");
        }

        // Fallback: singolo file con titolo
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx'],
        ]);
        $path = $request->file('file')->store('kb/'.$tenant->id, 'public');
        $doc = Document::create([
            'tenant_id' => $tenant->id,
            'title' => $data['title'],
            'source' => 'upload',
            'path' => $path,
            'ingestion_status' => 'pending',
        ]);
        IngestUploadedDocumentJob::dispatch($doc->id)->onQueue('ingestion');
        return back()->with('ok', 'Documento caricato: ingestion avviata');
    }

    public function retry(Tenant $tenant, Document $document)
    {
        if ($document->tenant_id !== $tenant->id) {
            abort(404);
        }
        $document->update(['ingestion_status' => 'pending']);
        IngestUploadedDocumentJob::dispatch($document->id)->onQueue('ingestion');
        return back()->with('ok', 'Ingestion riavviata per il documento #'.$document->id);
    }

    public function destroy(Tenant $tenant, Document $document, MilvusClient $milvus)
    {
        if ($document->tenant_id !== $tenant->id) {
            abort(404);
        }
        // Cancella vettori su Milvus in background
        DeleteVectorsJob::dispatch([$document->id])->onQueue('indexing');
        // Elimina file se esiste
        if ($document->path && Storage::disk('public')->exists($document->path)) {
            Storage::disk('public')->delete($document->path);
        }
        // Elimina righe chunks e documento
        \DB::table('document_chunks')->where('document_id', $document->id)->delete();
        $document->delete();
        return redirect()->route('admin.documents.index', $tenant)->with('ok', 'Documento eliminato');
    }

    public function destroyAll(Tenant $tenant, MilvusClient $milvus)
    {
        $docs = Document::where('tenant_id', $tenant->id)->get(['id','path']);
        if ($docs->isEmpty()) {
            return redirect()->route('admin.documents.index', $tenant)->with('ok', 'Nessun documento da eliminare');
        }

        // 1) Pulisci Milvus (rispetta QUEUE_CONNECTION=sync)
        DeleteVectorsJob::dispatch($docs->pluck('id')->all())->onQueue('indexing');

        // 2) Cancella dati strutturati e file
        \DB::table('document_chunks')->whereIn('document_id', $docs->pluck('id'))->delete();
        foreach ($docs as $d) {
            if ($d->path && Storage::disk('public')->exists($d->path)) {
                Storage::disk('public')->delete($d->path);
            }
        }
        Document::whereIn('id', $docs->pluck('id'))->delete();

        return redirect()->route('admin.documents.index', $tenant)->with('ok', 'Tutti i documenti eliminati');
    }
}
