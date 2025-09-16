<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatbotFeedback;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChatbotFeedbackController extends Controller
{
    /**
     * Salva un feedback per una risposta del chatbot
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        
        // Validazione input
        $validator = Validator::make($request->all(), [
            'user_question' => 'required|string|max:2000',
            'bot_response' => 'required|string|max:10000',
            'rating' => 'required|in:negative,neutral,positive',
            'comment' => 'nullable|string|max:1000',
            'session_id' => 'nullable|string|max:255',
            'conversation_id' => 'nullable|string|max:255',
            'message_id' => 'nullable|string|max:255',
            'page_url' => 'nullable|url|max:500',
            'response_metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dati non validi',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verifica che il tenant esista
            $tenant = Tenant::find($tenantId);
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant non trovato'
                ], 404);
            }

            // Prepara i dati del feedback
            $feedbackData = [
                'tenant_id' => $tenantId,
                'user_question' => $request->input('user_question'),
                'bot_response' => $request->input('bot_response'),
                'rating' => $request->input('rating'),
                'comment' => $request->input('comment'),
                'session_id' => $request->input('session_id'),
                'conversation_id' => $request->input('conversation_id'),
                'message_id' => $request->input('message_id'),
                'page_url' => $request->input('page_url'),
                'response_metadata' => $request->input('response_metadata'),
                'ip_address' => $request->ip(),
                'user_agent_data' => [
                    'user_agent' => $request->userAgent(),
                    'accept_language' => $request->header('Accept-Language'),
                    'referer' => $request->header('Referer'),
                ],
                'feedback_given_at' => now(),
            ];

            // Salva il feedback
            $feedback = ChatbotFeedback::create($feedbackData);

            return response()->json([
                'success' => true,
                'message' => 'Feedback salvato con successo',
                'data' => [
                    'feedback_id' => $feedback->id,
                    'rating' => $feedback->rating,
                    'rating_emoji' => $feedback->rating_emoji,
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Errore nel salvare il feedback del chatbot', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore interno del server'
            ], 500);
        }
    }

    /**
     * Recupera le statistiche dei feedback per un tenant
     */
    public function stats(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id');

        try {
            $stats = ChatbotFeedback::forTenant($tenantId)
                ->selectRaw('rating, COUNT(*) as count')
                ->groupBy('rating')
                ->get()
                ->keyBy('rating');

            $result = [
                'total' => ChatbotFeedback::forTenant($tenantId)->count(),
                'positive' => $stats->get('positive')->count ?? 0,
                'neutral' => $stats->get('neutral')->count ?? 0,
                'negative' => $stats->get('negative')->count ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            \Log::error('Errore nel recuperare le statistiche feedback', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore interno del server'
            ], 500);
        }
    }
}
