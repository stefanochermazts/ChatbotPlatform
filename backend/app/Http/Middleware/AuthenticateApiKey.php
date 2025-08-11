<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Missing Bearer token'], 401);
        }

        $token = substr($header, 7);

        /** @var ApiKey|null $apiKey */
        $apiKey = ApiKey::query()
            ->where('key_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($apiKey === null) {
            return response()->json(['message' => 'Invalid API key'], 401);
        }

        /** @var Tenant $tenant */
        $tenant = $apiKey->tenant;

        // Scope tenant nel container richiesta
        app()->instance(Tenant::class, $tenant);
        $request->attributes->set('tenant_id', $tenant->id);

        return $next($request);
    }
}



