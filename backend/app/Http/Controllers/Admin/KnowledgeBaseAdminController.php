<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Models\Tenant;
use Illuminate\Http\Request;

class KnowledgeBaseAdminController extends Controller
{
    public function store(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $kb = KnowledgeBase::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);
        if ($kb->is_default) {
            KnowledgeBase::where('tenant_id', $tenant->id)->where('id', '!=', $kb->id)->update(['is_default' => false]);
        }
        return back()->with('ok', 'Knowledge base creata');
    }

    public function update(Request $request, Tenant $tenant, KnowledgeBase $knowledgeBase)
    {
        if ($knowledgeBase->tenant_id !== $tenant->id) {
            abort(404);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $knowledgeBase->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);
        if ($knowledgeBase->is_default) {
            KnowledgeBase::where('tenant_id', $tenant->id)->where('id', '!=', $knowledgeBase->id)->update(['is_default' => false]);
        }
        return back()->with('ok', 'Knowledge base aggiornata');
    }

    public function destroy(Tenant $tenant, KnowledgeBase $knowledgeBase)
    {
        if ($knowledgeBase->tenant_id !== $tenant->id) {
            abort(404);
        }
        // Rimuovi default se necessario
        $wasDefault = $knowledgeBase->is_default;
        $knowledgeBase->delete();
        if ($wasDefault) {
            $newDefault = KnowledgeBase::where('tenant_id', $tenant->id)->first();
            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }
        return back()->with('ok', 'Knowledge base eliminata');
    }
}


