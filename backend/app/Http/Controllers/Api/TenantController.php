<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateMilvusPartitionJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'domain' => $validated['domain'] ?? null,
            'plan' => $validated['plan'] ?? 'free',
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // Crea automaticamente la partizione Milvus per questo tenant
        CreateMilvusPartitionJob::dispatch($tenant->id);

        return response()->json($tenant, 201);
    }

    public function addUser(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = User::findOrFail($validated['user_id']);
        $tenant->users()->syncWithoutDetaching([$user->id => ['role' => $validated['role']]]);

        return response()->json(['status' => 'ok']);
    }
}
