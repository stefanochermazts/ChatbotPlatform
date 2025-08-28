<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestUploadedDocumentJob;
use App\Jobs\DeleteVectorsJob;
use App\Jobs\DeleteVectorsJobFixed;
use App\Models\Document;
use App\Models\Tenant;
use App\Services\RAG\MilvusClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentAdminController extends Controller
{
    public function index(Request $request, Tenant $tenant)
    {
        $kbId = (int) $request->query('kb_id', 0);
        $sourceUrlSearch = $request->query('source_url', '');
        
        $query = Document::where('tenant_id', $tenant->id);
        
        if ($kbId > 0) {
            $query->where('knowledge_base_id', $kbId);
        }
        
        if (!empty($sourceUrlSearch)) {
            $query->where('source_url', 'ILIKE', '%' . $sourceUrlSearch . '%');
        }
        
        $docs = $query->orderByDesc('id')->paginate(20)->withQueryString();
        return view('admin.documents.index', compact('tenant', 'docs', 'kbId', 'sourceUrlSearch'));
    }

    public function upload(Request $request, Tenant $tenant)
    {
        // Se presente input multiplo `files[]`, gestiamo batch upload
        if ($request->hasFile('files')) {
            $request->validate([
                'files' => ['required', 'array', 'min:1'],
                'files.*' => ['file', 'mimes:pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx'],
                'knowledge_base_id' => ['nullable', 'integer', 'exists:knowledge_bases,id'],
            ]);

            $created = 0;
            $errors = 0;
            foreach ($request->file('files', []) as $file) {
                try {
                    $original = (string) $file->getClientOriginalName();
                    $title = pathinfo($original, PATHINFO_FILENAME) ?: 'Documento';
                    $path = $file->store('kb/'.$tenant->id, 'public');

                    $kbId = null;
                    if ($request->filled('knowledge_base_id')) {
                        $kbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)
                            ->where('id', (int) $request->input('knowledge_base_id'))
                            ->value('id');
                    }
                    if (!$kbId) {
                        $kbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->where('is_default', true)->value('id');
                    }
                    $doc = Document::create([
                        'tenant_id' => $tenant->id,
                        'knowledge_base_id' => $kbId,
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
            'knowledge_base_id' => ['nullable', 'integer', 'exists:knowledge_bases,id'],
        ]);
        $path = $request->file('file')->store('kb/'.$tenant->id, 'public');
        $kbId = null;
        if ($request->filled('knowledge_base_id')) {
            $kbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)
                ->where('id', (int) $request->input('knowledge_base_id'))
                ->value('id');
        }
        if (!$kbId) {
            $kbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->where('is_default', true)->value('id');
        }
        $doc = Document::create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $kbId,
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
        
        // 🚀 FIXED: Calcola primaryIds PRIMA di cancellare chunks da PostgreSQL
        DeleteVectorsJobFixed::fromDocumentIds([$document->id])->dispatch();
        
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

        // 🚀 FIXED: Calcola primaryIds PRIMA di cancellare chunks da PostgreSQL
        DeleteVectorsJobFixed::fromDocumentIds($docs->pluck('id')->all())->dispatch();

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

    public function destroyByKb(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'knowledge_base_id' => ['required', 'integer', 'exists:knowledge_bases,id'],
        ]);
        $kbId = (int) $data['knowledge_base_id'];
        // Seleziona documenti del tenant per la KB indicata
        $docs = Document::where('tenant_id', $tenant->id)
            ->where('knowledge_base_id', $kbId)
            ->get(['id','path']);
        if ($docs->isEmpty()) {
            return redirect()->route('admin.documents.index', $tenant)->with('ok', 'Nessun documento da eliminare per la KB selezionata');
        }

        // 🚀 FIXED: Calcola primaryIds PRIMA di cancellare chunks da PostgreSQL
        DeleteVectorsJobFixed::fromDocumentIds($docs->pluck('id')->all())->dispatch();

        // 2) Cancella dati strutturati e file
        \DB::table('document_chunks')->whereIn('document_id', $docs->pluck('id'))->delete();
        foreach ($docs as $d) {
            if ($d->path && Storage::disk('public')->exists($d->path)) {
                Storage::disk('public')->delete($d->path);
            }
        }
        Document::whereIn('id', $docs->pluck('id'))->delete();

        return redirect()->route('admin.documents.index', $tenant)->with('ok', 'Documenti della KB selezionata eliminati');
    }

    /**
     * 🔄 NUOVA FUNZIONALITÀ: Re-scraping di un singolo documento
     */
    public function rescrape(Document $document)
    {
        if (!$document->source_url) {
            return response()->json([
                'success' => false,
                'message' => 'Documento non ha source_url. Non può essere ri-scrapato.'
            ], 400);
        }

        try {
            $scraperService = new \App\Services\Scraper\WebScraperService();
            $result = $scraperService->forceRescrapDocument($document->id);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'document_id' => $result['document_id'],
                        'original_document' => $result['original_document'],
                        'result' => $result['result']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il re-scraping: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🔄 NUOVA FUNZIONALITÀ: Re-scraping di tutti i documenti con source_url per un tenant
     */
    public function rescrapeAll(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'confirm' => ['required', 'boolean', 'accepted']
        ]);

        try {
            // Trova tutti i documenti con source_url per questo tenant
            $documents = Document::where('tenant_id', $tenant->id)
                ->whereNotNull('source_url')
                ->where('source_url', '!=', '')
                ->get();

            if ($documents->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nessun documento con source_url trovato per questo tenant.'
                ], 400);
            }

            $scraperService = new \App\Services\Scraper\WebScraperService();
            $successCount = 0;
            $failureCount = 0;
            $errors = [];

            foreach ($documents as $document) {
                try {
                    $result = $scraperService->forceRescrapDocument($document->id);
                    
                    if ($result['success']) {
                        $successCount++;
                    } else {
                        $failureCount++;
                        $errors[] = "Doc #{$document->id}: " . $result['message'];
                    }
                    
                } catch (\Exception $e) {
                    $failureCount++;
                    $errors[] = "Doc #{$document->id}: " . $e->getMessage();
                }
                
                // Rate limiting per evitare sovraccarico
                usleep(500000); // 0.5 secondi
            }

            return response()->json([
                'success' => $failureCount === 0,
                'message' => "Re-scraping completato. Successi: {$successCount}, Fallimenti: {$failureCount}",
                'data' => [
                    'total_documents' => $documents->count(),
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                    'errors' => array_slice($errors, 0, 10) // Max 10 errori nel response
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il re-scraping batch: ' . $e->getMessage()
            ], 500);
        }
    }
}
