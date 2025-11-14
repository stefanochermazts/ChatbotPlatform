<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Admin-Token');
        $expected = (string) config('app.admin_token');

        $sessionOk = (bool) $request->session()->get('admin_authenticated', false);

        if ($sessionOk || ($expected !== '' && hash_equals($expected, (string) $token))) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return redirect()->route('admin.login');
    }
}
