<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticated
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $role = null): Response
    {
        // Verifica se l'utente è autenticato
        if (!Auth::check()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            return redirect()->route('login');
        }

        $user = Auth::user();

        // Verifica se l'utente è attivo
        if (!$user->is_active) {
            Auth::logout();
            return redirect()->route('login')
                ->withErrors(['email' => 'Il tuo account è stato disattivato.']);
        }

        // Verifica se l'email è verificata
        if (!$user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        // Verifica ruolo specifico se richiesto
        if ($role) {
            switch ($role) {
                case 'admin':
                    if (!$user->isAdmin()) {
                        abort(403, 'Accesso negato. Sono richiesti privilegi di amministratore.');
                    }
                    break;
                case 'customer':
                    // I clienti possono accedere solo ai loro tenant
                    // Questa verifica specifica sarà gestita nei controller
                    break;
                default:
                    abort(403, 'Ruolo non riconosciuto.');
            }
        }

        return $next($request);
    }
}
