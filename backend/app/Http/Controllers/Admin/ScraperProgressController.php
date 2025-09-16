<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScraperProgress;
use App\Models\Tenant;
use Illuminate\Http\Request;

class ScraperProgressController extends Controller
{
    /**
     * Ottieni progress attuale per un tenant
     */
    public function current(Request $request, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        $progress = ScraperProgress::where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->latest()
            ->first();

        if (!$progress) {
            return response()->json([
                'active' => false,
                'message' => 'Nessun scraping attivo'
            ]);
        }

        return response()->json([
            'active' => true,
            'progress' => $progress->getSummary()
        ]);
    }

    /**
     * Storico progress per un tenant
     */
    public function history(Request $request, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        $history = ScraperProgress::where('tenant_id', $tenantId)
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($p) => $p->getSummary());

        return response()->json([
            'history' => $history
        ]);
    }

    /**
     * Dettaglio sessione specifica
     */
    public function session(Request $request, string $sessionId)
    {
        $progress = ScraperProgress::where('session_id', $sessionId)->firstOrFail();
        
        return response()->json([
            'progress' => $progress->getSummary()
        ]);
    }

    /**
     * Cancella sessione di scraping
     */
    public function cancel(Request $request, string $sessionId)
    {
        $progress = ScraperProgress::where('session_id', $sessionId)
            ->where('status', 'running')
            ->firstOrFail();

        $progress->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'last_error' => 'Cancellato dall\'utente'
        ]);

        // TODO: Implementa logica per fermare effettivamente il job
        
        return response()->json([
            'message' => 'Scraping cancellato',
            'progress' => $progress->getSummary()
        ]);
    }

    /**
     * Dashboard overview per tenant
     */
    public function dashboard(Request $request, int $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);
        
        // Progress attuale
        $currentProgress = ScraperProgress::where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->latest()
            ->first();

        // Statistiche ultime 24h
        $recentStats = ScraperProgress::where('tenant_id', $tenantId)
            ->where('started_at', '>=', now()->subDay())
            ->selectRaw('
                COUNT(*) as sessions_count,
                SUM(pages_scraped) as total_pages_scraped,
                SUM(documents_created + documents_updated) as total_documents,
                SUM(ingestion_completed) as total_ingestion_completed,
                AVG(CASE WHEN completed_at IS NOT NULL 
                    THEN EXTRACT(epoch FROM (completed_at - started_at)) 
                    ELSE NULL END) as avg_duration_seconds
            ')
            ->first();

        return response()->json([
            'current_progress' => $currentProgress?->getSummary(),
            'stats_24h' => [
                'sessions' => $recentStats->sessions_count ?? 0,
                'pages_scraped' => $recentStats->total_pages_scraped ?? 0,
                'documents' => $recentStats->total_documents ?? 0,
                'ingestion_completed' => $recentStats->total_ingestion_completed ?? 0,
                'avg_duration_minutes' => $recentStats->avg_duration_seconds ? round($recentStats->avg_duration_seconds / 60, 1) : null,
            ]
        ]);
    }
}



