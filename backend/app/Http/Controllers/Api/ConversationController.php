<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Models\Tenant;
use App\Models\WidgetConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    /**
     * 🚀 Avvia una nuova sessione di conversazione
     */
    public function start(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'tenant_id' => 'required|integer|exists:tenants,id',
                'widget_config_id' => 'required|integer|exists:widget_configs,id',
                'user_identifier' => 'nullable|string|max:255',
                'channel' => 'string|in:widget,api,whatsapp,telegram|max:50',
                'user_agent' => 'nullable|string|max:500',
                'referrer_url' => 'nullable|url|max:500',
                'browser_info' => 'nullable|array',
                'metadata' => 'nullable|array',
            ]);

            // 🔒 Verifica tenant esistente
            $tenant = Tenant::find($validated['tenant_id']);

            if (! $tenant) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }

            // 🔒 Verifica widget config abilitato
            $widgetConfig = WidgetConfig::where('id', $validated['widget_config_id'])
                ->where('tenant_id', $validated['tenant_id'])
                ->where('enabled', true)
                ->first();

            if (! $widgetConfig) {
                return response()->json(['error' => 'Widget configuration not found or disabled'], 404);
            }

            // 🎯 Genera session ID univoco
            $sessionId = Str::uuid()->toString();

            // 📝 Crea nuova sessione
            $session = ConversationSession::create([
                'tenant_id' => $validated['tenant_id'],
                'widget_config_id' => $validated['widget_config_id'],
                'session_id' => $sessionId,
                'user_identifier' => $validated['user_identifier'] ?? $this->generateUserIdentifier($request),
                'channel' => $validated['channel'] ?? 'widget',
                'user_agent' => $validated['user_agent'] ?? null,
                'referrer_url' => $validated['referrer_url'] ?? null,
                'browser_info' => $validated['browser_info'] ?? null,
                'status' => 'active',
                'handoff_status' => 'bot_only',
                'started_at' => now(),
                'last_activity_at' => now(),
                'metadata' => $validated['metadata'] ?? [],
            ]);

            return response()->json([
                'success' => true,
                'session' => [
                    'session_id' => $session->session_id,
                    'status' => $session->status,
                    'handoff_status' => $session->handoff_status,
                    'started_at' => $session->started_at->toISOString(),
                ],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('conversation.start.failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to start conversation'], 500);
        }
    }

    /**
     * 🔄 Recupera una sessione esistente
     */
    public function show(string $sessionId): JsonResponse
    {
        // TODO: Implementazione completa show method
        return response()->json(['message' => 'Show method - to be implemented']);
    }

    /**
     * 🛑 Termina una sessione di conversazione
     */
    public function end(string $sessionId, Request $request): JsonResponse
    {
        // TODO: Implementazione completa end method
        return response()->json(['message' => 'End method - to be implemented']);
    }

    /**
     * 📊 Ottieni lo status di una conversazione
     */
    public function status(string $sessionId): JsonResponse
    {
        // TODO: Implementazione completa status method
        return response()->json(['message' => 'Status method - to be implemented']);
    }

    /**
     * 🔧 Helper: Genera identificatore utente univoco
     */
    private function generateUserIdentifier(Request $request): string
    {
        $components = [
            $request->ip(),
            $request->header('User-Agent'),
            $request->header('Accept-Language'),
        ];

        return 'guest_'.md5(implode('|', array_filter($components)));
    }
}
