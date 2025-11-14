<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function issue(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['nullable', 'array'],
        ]);

        $plain = 'sk-'.Str::random(48);
        $apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'key_hash' => hash('sha256', $plain),
            'scopes' => $validated['scopes'] ?? null,
        ]);

        return response()->json([
            'id' => $apiKey->id,
            'name' => $apiKey->name,
            'key' => $plain, // mostrata solo una volta
            'created_at' => $apiKey->created_at,
        ], 201);
    }

    public function revoke(Tenant $tenant, string $keyId)
    {
        $apiKey = ApiKey::query()->where('tenant_id', $tenant->id)->findOrFail($keyId);
        $apiKey->update(['revoked_at' => now()]);

        return response()->json(['status' => 'revoked']);
    }
}
