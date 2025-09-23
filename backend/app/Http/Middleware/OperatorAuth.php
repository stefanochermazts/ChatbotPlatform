<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class OperatorAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 🔒 Verifica che l'utente sia autenticato
        if (!auth()->check()) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $user = auth()->user();

        // 👨‍💼 Verifica che l'utente sia un operatore
        if (!$user->isOperator()) {
            return response()->json(['error' => 'Operator access required'], 403);
        }

        // 🟢 Aggiorna last_seen per keep-alive
        $user->updateLastSeen();

        // ✅ Passa al prossimo middleware/controller
        return $next($request);
    }
}
