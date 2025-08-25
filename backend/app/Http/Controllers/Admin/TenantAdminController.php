<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateMilvusPartitionJob;
use App\Models\Tenant;
use App\Models\KnowledgeBase;
use App\Models\Document;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantAdminController extends Controller
{
    public function index()
    {
        $tenants = Tenant::orderBy('id', 'desc')->paginate(20);
        return view('admin.tenants.index', compact('tenants'));
    }

    public function create()
    {
        return view('admin.tenants.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug'],
            'domain' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:64'],
            'languages' => ['nullable', 'string'],
            'default_language' => ['nullable', 'string', 'max:10'],
            'custom_system_prompt' => ['nullable', 'string', 'max:4000'],
            'custom_context_template' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['languages'] = isset($data['languages']) && $data['languages'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $data['languages']))))
            : null;
        
        $tenant = Tenant::create($data);
        
        // Crea automaticamente la partizione Milvus per questo tenant
        CreateMilvusPartitionJob::dispatch($tenant->id);
        
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant creato');
    }

    public function edit(Tenant $tenant)
    {
        return view('admin.tenants.edit', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'unique:tenants,slug,'.$tenant->id],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:64'],
            'languages' => ['sometimes', 'nullable'],
            'default_language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'custom_system_prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'custom_context_template' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'intents_enabled' => ['sometimes', 'nullable', 'array'],
            'extra_intent_keywords' => ['sometimes', 'nullable', 'string'],
            'kb_scope_mode' => ['sometimes', 'nullable', 'in:relaxed,strict'],
            'intent_min_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'custom_synonyms' => ['sometimes', 'nullable', 'string'],
            'api_key_plain' => ['sometimes', 'nullable', 'string', 'min:20'],
        ]);
        
        // Parse languages se presente
        if (array_key_exists('languages', $data)) {
            $langVal = $data['languages'];
            if (is_string($langVal)) {
                $langVal = trim($langVal);
                $data['languages'] = $langVal !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $langVal))))
                    : null;
            } elseif (is_array($langVal)) {
                $data['languages'] = array_values(array_filter(array_map('trim', $langVal)));
                if ($data['languages'] === []) { $data['languages'] = null; }
            } else {
                $data['languages'] = null;
            }
        }
        
        if (array_key_exists('extra_intent_keywords', $data)) {
            $json = json_decode($data['extra_intent_keywords'] ?: '{}', true);
            $data['extra_intent_keywords'] = is_array($json) ? $json : null;
        }
        
        // Gestisci sinonimi personalizzati
        if (array_key_exists('custom_synonyms', $data)) {
            if (trim((string) $data['custom_synonyms']) === '') {
                $data['custom_synonyms'] = null; // Usa sinonimi di default
            } else {
                $json = json_decode((string) $data['custom_synonyms'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return back()->withErrors(['custom_synonyms' => 'Formato JSON non valido per i sinonimi'])->withInput();
                }
                $data['custom_synonyms'] = is_array($json) ? $json : null;
            }
        }
        
        // Salva l'API key plain (opzionale)
        if (!empty($data['api_key_plain'] ?? null)) {
            $plain = (string) $data['api_key_plain'];
            $hash = hash('sha256', $plain);

            // Se esiste già una chiave con lo stesso hash
            $existing = ApiKey::where('key_hash', $hash)->first();
            if ($existing) {
                // Se appartiene ad un altro tenant, impedisci riutilizzo
                if ($existing->tenant_id !== $tenant->id) {
                    return back()->withErrors([
                        'api_key_plain' => 'Questa API Key è già associata ad un altro tenant.'
                    ])->withInput();
                }
                // Aggiorna la chiave cifrata e riattiva se revocata
                $existing->update([
                    'name' => $existing->name ?: 'Widget API Key',
                    'key' => $plain,
                    'revoked_at' => null,
                    'scopes' => $existing->scopes ?? ['chat:write', 'events:write'],
                ]);
            } else {
                // Crea nuova chiave per il tenant corrente
                ApiKey::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Widget API Key',
                    'key' => $plain,
                    'key_hash' => $hash,
                    'scopes' => ['chat:write', 'events:write'],
                ]);
            }
        }
        unset($data['api_key_plain']);
        
        $tenant->update($data);
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant aggiornato');
    }

    public function bulkAssignKb(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'knowledge_base_id' => ['required', 'integer', 'exists:knowledge_bases,id'],
            'document_ids' => ['required', 'string'],
        ]);
        $kb = KnowledgeBase::where('tenant_id', $tenant->id)->findOrFail((int) $data['knowledge_base_id']);
        $ids = array_values(array_filter(array_map(fn($x)=> (int) trim($x), explode(',', $data['document_ids']))));
        if ($ids === []) {
            return back()->with('error', 'Nessun documento valido');
        }
        Document::where('tenant_id', $tenant->id)->whereIn('id', $ids)->update(['knowledge_base_id' => $kb->id]);
        return back()->with('ok', 'Documenti associati alla KB "'.$kb->name.'"');
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant eliminato');
    }

    /**
     * Create a new API key for the tenant
     */
    public function createApiKey(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['nullable', 'array'],
        ]);

        $plain = 'sk-'.Str::random(48);
        $apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'key' => $plain,
            'key_hash' => hash('sha256', $plain),
            'scopes' => $validated['scopes'] ?? null,
        ]);

        return redirect()
            ->route('admin.tenants.edit', $tenant)
            ->with('success', 'API Key creata con successo!')
            ->with('api_key', $plain) // Mostrata solo una volta
            ->with('api_key_name', $apiKey->name);
    }

    /**
     * Revoke an API key
     */
    public function revokeApiKey(Tenant $tenant, string $keyId)
    {
        $apiKey = ApiKey::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($keyId);
        
        $apiKey->update(['revoked_at' => now()]);
        
        return redirect()
            ->route('admin.tenants.edit', $tenant)
            ->with('success', 'API Key revocata con successo!');
    }
}

