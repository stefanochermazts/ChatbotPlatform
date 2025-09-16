<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotFeedback;
use App\Models\Tenant;
use Illuminate\Http\Request;

class FeedbackAdminController extends Controller
{
    /**
     * Mostra l'elenco dei feedback per un tenant
     */
    public function index(Request $request, Tenant $tenant)
    {
        $query = ChatbotFeedback::forTenant($tenant->id);
        
        // 🔍 FILTRI
        
        // Rating filter
        if ($request->filled('rating')) {
            $query->withRating($request->rating);
        }
        
        // Data range filter
        if ($request->filled('date_from')) {
            $query->whereDate('feedback_given_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('feedback_given_at', '<=', $request->date_to);
        }
        
        // Search in question/response
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('user_question', 'ILIKE', '%' . $search . '%')
                  ->orWhere('bot_response', 'ILIKE', '%' . $search . '%');
            });
        }
        
        // 📊 STATISTICHE
        $stats = [
            'total' => ChatbotFeedback::forTenant($tenant->id)->count(),
            'positive' => ChatbotFeedback::forTenant($tenant->id)->withRating('positive')->count(),
            'neutral' => ChatbotFeedback::forTenant($tenant->id)->withRating('neutral')->count(),
            'negative' => ChatbotFeedback::forTenant($tenant->id)->withRating('negative')->count(),
        ];
        
        // Calcola percentuali
        if ($stats['total'] > 0) {
            $stats['positive_percent'] = round(($stats['positive'] / $stats['total']) * 100, 1);
            $stats['neutral_percent'] = round(($stats['neutral'] / $stats['total']) * 100, 1);
            $stats['negative_percent'] = round(($stats['negative'] / $stats['total']) * 100, 1);
        } else {
            $stats['positive_percent'] = $stats['neutral_percent'] = $stats['negative_percent'] = 0;
        }
        
        // 📄 PAGINAZIONE
        $feedbacks = $query->orderBy('feedback_given_at', 'desc')->paginate(20);
        
        return view('admin.feedback.index', [
            'feedbacks' => $feedbacks,
            'tenant' => $tenant,
            'stats' => $stats,
            'ratings' => ChatbotFeedback::RATINGS,
        ]);
    }

    /**
     * Mostra i dettagli di un singolo feedback
     */
    public function show(Request $request, Tenant $tenant, ChatbotFeedback $feedback)
    {
        // Verifica che il feedback appartenga al tenant
        if ($feedback->tenant_id !== $tenant->id) {
            abort(404);
        }

        return view('admin.feedback.show', [
            'feedback' => $feedback,
            'tenant' => $tenant,
        ]);
    }

    /**
     * Elimina un feedback
     */
    public function destroy(Request $request, Tenant $tenant, ChatbotFeedback $feedback)
    {
        // Verifica che il feedback appartenga al tenant
        if ($feedback->tenant_id !== $tenant->id) {
            abort(404);
        }

        $feedback->delete();

        return redirect()->route('admin.tenants.feedback.index', $tenant)
                         ->with('success', 'Feedback eliminato con successo.');
    }

    /**
     * Esporta i feedback in CSV
     */
    public function export(Request $request, Tenant $tenant)
    {
        $query = ChatbotFeedback::forTenant($tenant->id);
        
        // Applica stessi filtri della index
        if ($request->filled('rating')) {
            $query->withRating($request->rating);
        }
        
        if ($request->filled('date_from')) {
            $query->whereDate('feedback_given_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->whereDate('feedback_given_at', '<=', $request->date_to);
        }
        
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('user_question', 'ILIKE', '%' . $search . '%')
                  ->orWhere('bot_response', 'ILIKE', '%' . $search . '%');
            });
        }
        
        $feedbacks = $query->orderBy('feedback_given_at', 'desc')->get();
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="feedback_' . $tenant->slug . '_' . date('Y-m-d') . '.csv"',
        ];
        
        $callback = function() use ($feedbacks) {
            $file = fopen('php://output', 'w');
            
            // Header CSV
            fputcsv($file, [
                'ID',
                'Data Feedback',
                'Rating',
                'Domanda Utente',
                'Risposta Bot',
                'Commento',
                'Session ID',
                'Page URL',
                'IP Address',
                'User Agent'
            ]);
            
            // Dati
            foreach ($feedbacks as $feedback) {
                fputcsv($file, [
                    $feedback->id,
                    $feedback->feedback_given_at->format('Y-m-d H:i:s'),
                    $feedback->rating_text,
                    $feedback->user_question,
                    \Str::limit($feedback->bot_response, 200),
                    $feedback->comment,
                    $feedback->session_id,
                    $feedback->page_url,
                    $feedback->ip_address,
                    $feedback->user_agent_data['user_agent'] ?? ''
                ]);
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
}
