<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OperatorRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperatorController extends Controller
{
    public function __construct(
        private OperatorRoutingService $routingService
    ) {}

    /**
     * 👥 Lista operatori disponibili
     */
    public function available(Request $request): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Available operators - to be implemented']);
    }

    /**
     * 🔄 Aggiorna status operatore
     */
    public function updateStatus(Request $request): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Update operator status - to be implemented']);
    }

    /**
     * 💬 Conversazioni assegnate a operatore
     */
    public function conversations(Request $request, int $operatorId): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Operator conversations - to be implemented']);
    }

    /**
     * 📊 Metriche operatore
     */
    public function metrics(Request $request, int $operatorId): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Operator metrics - to be implemented']);
    }

    /**
     * 💓 Heartbeat operatore (keep-alive)
     */
    public function heartbeat(Request $request): JsonResponse
    {
        // TODO: Implementazione completa
        return response()->json(['message' => 'Operator heartbeat - to be implemented']);
    }
}
