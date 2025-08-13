<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\KnowledgeBase;
use App\Models\Document;
use Illuminate\Http\Request;

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
        Tenant::create($data);
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant creato');
    }

    public function edit(Tenant $tenant)
    {
        return view('admin.tenants.edit', compact('tenant'));
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:tenants,slug,'.$tenant->id],
            'domain' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:64'],
            'languages' => ['nullable', 'string'],
            'default_language' => ['nullable', 'string', 'max:10'],
            'custom_system_prompt' => ['nullable', 'string', 'max:4000'],
            'custom_context_template' => ['nullable', 'string', 'max:2000'],
            'intents_enabled' => ['nullable', 'array'],
            'extra_intent_keywords' => ['nullable', 'string'],
            'kb_scope_mode' => ['nullable', 'in:relaxed,strict'],
            'intent_min_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);
        $data['languages'] = isset($data['languages']) && $data['languages'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $data['languages']))))
            : null;
        $data['languages'] = isset($data['languages']) && $data['languages'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $data['languages']))))
            : null;
        if (isset($data['extra_intent_keywords'])) {
            $json = json_decode($data['extra_intent_keywords'] ?: '{}', true);
            $data['extra_intent_keywords'] = is_array($json) ? $json : null;
        }
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
}

