<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestUploadedDocumentJob;
use App\Jobs\DeleteVectorsJobFixed;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\ScraperConfig;
use App\Services\RAG\MilvusClient;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
                    // Validazione file base
                    if (!$file->isValid()) {
                        throw new \Exception('File non valido');
                    }
                    
                    $original = (string) $file->getClientOriginalName();
                    $title = pathinfo($original, PATHINFO_FILENAME) ?: 'Documento';
                    
                    // Fix: genera un nome file sicuro per evitare "Path cannot be empty"
                    $extension = $file->getClientOriginalExtension();
                    if (empty($extension)) {
                        $extension = 'bin'; // fallback per file senza estensione
                    }
                    $safeName = \Str::random(40) . '.' . $extension;
                    
                    // DEBUG: Prova metodo alternativo senza storeAs
                    $directory = storage_path('app/public/kb/' . $tenant->id);
                    $fullPath = $directory . '/' . $safeName;
                    
                    \Log::info('Tentativo salvataggio manuale', [
                        'original_name' => $original,
                        'safe_name' => $safeName,
                        'directory' => $directory,
                        'full_path' => $fullPath,
                        'file_exists' => $file->isValid(),
                        'file_size' => $file->getSize()
                    ]);
                    
                    // Assicurati che la directory esista
                    if (!is_dir($directory)) {
                        mkdir($directory, 0755, true);
                    }
                    
                    // Prova a spostare il file manualmente
                    if (!$file->move($directory, $safeName)) {
                        throw new \Exception('Impossibile spostare il file nella directory di destinazione');
                    }
                    
                    $path = 'kb/' . $tenant->id . '/' . $safeName;

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
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::error('Upload document failed', [
                        'tenant_id' => $tenant->id,
                        'file' => $original ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
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

        // 🚀 MIGLIORAMENTO: Cancellazione sincrona + asincrona per sicurezza
        try {
            // 1) Cancellazione sincrona DIRETTA di tutto il tenant da Milvus
            \Log::info('🗑️ [DESTROY-ALL] Cancellazione sincrona tenant da Milvus', [
                'tenant_id' => $tenant->id,
                'documents_count' => $docs->count()
            ]);
            $success = $milvus->deleteByTenant($tenant->id);
            if ($success) {
                \Log::info('✅ [DESTROY-ALL] Milvus tenant cleanup successful', ['tenant_id' => $tenant->id]);
            } else {
                \Log::warning('⚠️ [DESTROY-ALL] Milvus tenant cleanup failed', ['tenant_id' => $tenant->id]);
            }
        } catch (\Exception $e) {
            \Log::error('❌ [DESTROY-ALL] Exception during Milvus cleanup', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage()
            ]);
        }

        // 2) BACKUP: Job asincrono per chunk specifici (se la cancellazione tenant fallisce)
        DeleteVectorsJobFixed::fromDocumentIds($docs->pluck('id')->all())->dispatch();

        // 3) Cancella dati strutturati e file
        \DB::table('document_chunks')->whereIn('document_id', $docs->pluck('id'))->delete();
        foreach ($docs as $d) {
            if ($d->path && Storage::disk('public')->exists($d->path)) {
                Storage::disk('public')->delete($d->path);
            }
        }
        Document::whereIn('id', $docs->pluck('id'))->delete();

        return redirect()->route('admin.documents.index', $tenant)->with('ok', 'Tutti i documenti eliminati');
    }

    public function destroyByKb(Request $request, Tenant $tenant, MilvusClient $milvus)
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

        // 🚀 MIGLIORAMENTO: Cancellazione sincrona + asincrona per sicurezza
        $documentIds = $docs->pluck('id')->all();
        
        try {
            // 1) Calcola primaryIds per cancellazione diretta da Milvus
            $chunks = \DB::table('document_chunks')
                ->whereIn('document_id', $documentIds)
                ->select('document_id', 'chunk_index')
                ->get();

            if ($chunks->isNotEmpty()) {
                $primaryIds = [];
                foreach ($chunks as $chunk) {
                    $primaryIds[] = (int) ($chunk->document_id * 100000 + $chunk->chunk_index);
                }

                \Log::info('🗑️ [DESTROY-KB] Cancellazione sincrona KB da Milvus', [
                    'tenant_id' => $tenant->id,
                    'kb_id' => $kbId,
                    'documents_count' => count($documentIds),
                    'chunks_count' => count($primaryIds)
                ]);

                // 2) Cancellazione sincrona diretta da Milvus
                $milvus->deleteByPrimaryIds($primaryIds);
                \Log::info('✅ [DESTROY-KB] Milvus KB cleanup successful', [
                    'tenant_id' => $tenant->id,
                    'kb_id' => $kbId,
                    'primary_ids_deleted' => count($primaryIds)
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('❌ [DESTROY-KB] Exception during Milvus cleanup', [
                'tenant_id' => $tenant->id,
                'kb_id' => $kbId,
                'error' => $e->getMessage()
            ]);
        }

        // 3) BACKUP: Job asincrono per sicurezza (se cancellazione sincrona fallisce)
        DeleteVectorsJobFixed::fromDocumentIds($documentIds)->dispatch();

        // 4) Cancella dati strutturati e file
        \DB::table('document_chunks')->whereIn('document_id', $documentIds)->delete();
        foreach ($docs as $d) {
            if ($d->path && Storage::disk('public')->exists($d->path)) {
                Storage::disk('public')->delete($d->path);
            }
        }
        Document::whereIn('id', $documentIds)->delete();

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
            'confirm' => ['required', 'boolean', 'accepted'],
            'kb_id' => ['nullable', 'integer'],
            'source_url' => ['nullable', 'string']
        ]);

        try {
            // Applica gli stessi filtri dell'index per consistenza
            $query = Document::where('tenant_id', $tenant->id)
                ->whereNotNull('source_url')
                ->where('source_url', '!=', '');
            
            // Applica filtro KB se specificato
            if (!empty($data['kb_id']) && $data['kb_id'] > 0) {
                $query->where('knowledge_base_id', $data['kb_id']);
            }
            
            // Applica filtro source_url se specificato
            if (!empty($data['source_url'])) {
                $query->where('source_url', 'ILIKE', '%' . $data['source_url'] . '%');
            }
            
            $documents = $query->get();

            if ($documents->isEmpty()) {
                $filterMsg = '';
                if (!empty($data['kb_id']) || !empty($data['source_url'])) {
                    $filterMsg = ' con i filtri applicati';
                }
                return response()->json([
                    'success' => false,
                    'message' => "Nessun documento con source_url trovato per questo tenant{$filterMsg}."
                ], 400);
            }

            $scraperService = new \App\Services\Scraper\WebScraperService();
            $successCount = 0;
            $failureCount = 0;
            $errors = [];
            $totalDocuments = $documents->count();

            \Log::info("🔄 [BATCH-RESCRAPE] Inizio batch re-scraping", [
                'tenant_id' => $tenant->id,
                'total_documents' => $totalDocuments,
                'filters_applied' => [
                    'kb_id' => $data['kb_id'] ?? null,
                    'source_url' => $data['source_url'] ?? null
                ]
            ]);

            foreach ($documents as $index => $document) {
                $currentDoc = $index + 1;
                
                \Log::info("📋 [BATCH-RESCRAPE] Processando documento {$currentDoc}/{$totalDocuments}", [
                    'document_id' => $document->id,
                    'title' => $document->title,
                    'source_url' => $document->source_url,
                    'progress_percent' => round(($currentDoc / $totalDocuments) * 100, 1)
                ]);
                
                try {
                    $result = $scraperService->forceRescrapDocument($document->id);
                    
                    if ($result['success']) {
                        $successCount++;
                        \Log::info("✅ [BATCH-RESCRAPE] Documento {$currentDoc}/{$totalDocuments} completato", [
                            'document_id' => $document->id,
                            'status' => 'success'
                        ]);
                    } else {
                        $failureCount++;
                        $errors[] = "Doc #{$document->id}: " . $result['message'];
                        \Log::warning("❌ [BATCH-RESCRAPE] Documento {$currentDoc}/{$totalDocuments} fallito", [
                            'document_id' => $document->id,
                            'status' => 'failed',
                            'error' => $result['message']
                        ]);
                    }
                    
                } catch (\Exception $e) {
                    $failureCount++;
                    $errors[] = "Doc #{$document->id}: " . $e->getMessage();
                    \Log::error("💥 [BATCH-RESCRAPE] Documento {$currentDoc}/{$totalDocuments} errore", [
                        'document_id' => $document->id,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Log di milestone ogni 10 documenti per batch grandi
                if ($currentDoc % 10 === 0 || $currentDoc === $totalDocuments) {
                    \Log::info("📊 [BATCH-RESCRAPE] Milestone {$currentDoc}/{$totalDocuments}", [
                        'progress_percent' => round(($currentDoc / $totalDocuments) * 100, 1),
                        'successi' => $successCount,
                        'fallimenti' => $failureCount,
                        'remaining' => $totalDocuments - $currentDoc
                    ]);
                }
                
                // Rate limiting per evitare sovraccarico
                usleep(500000); // 0.5 secondi
            }

            \Log::info("🏁 [BATCH-RESCRAPE] Batch completato", [
                'tenant_id' => $tenant->id,
                'total_documents' => $totalDocuments,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'success_rate' => round(($successCount / $totalDocuments) * 100, 1) . '%'
            ]);

            return response()->json([
                'success' => $failureCount === 0,
                'message' => "Re-scraping completato. Successi: {$successCount}, Fallimenti: {$failureCount}",
                'data' => [
                    'total_documents' => $totalDocuments,
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
