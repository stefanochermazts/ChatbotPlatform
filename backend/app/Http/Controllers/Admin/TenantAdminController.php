<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
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
        ]);
        $data['languages'] = isset($data['languages']) && $data['languages'] !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $data['languages']))))
            : null;
        $tenant->update($data);
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant aggiornato');
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();
        return redirect()->route('admin.tenants.index')->with('ok', 'Tenant eliminato');
    }
}

