@extends('admin.layout')

@section('content')
<div class="grid gap-6">
  <div class="bg-white border rounded p-6">
    <h1 class="text-xl font-semibold mb-4">Modifica Cliente: {{ $tenant->name }}</h1>
    
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4">
      @csrf @method('put')
      
      <!-- Informazioni base -->
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Nome *</span>
          <input name="name" value="{{ old('name', $tenant->name) }}" class="w-full border rounded px-3 py-2 mt-1" required />
        </label>
        <label class="block">
          <span class="text-sm font-medium">Slug *</span>
          <input name="slug" value="{{ old('slug', $tenant->slug) }}" class="w-full border rounded px-3 py-2 mt-1" required />
        </label>
      </div>
      
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Dominio (opzionale)</span>
          <input name="domain" value="{{ old('domain', $tenant->domain) }}" class="w-full border rounded px-3 py-2 mt-1" />
        </label>
        <label class="block">
          <span class="text-sm font-medium">Piano (opzionale)</span>
          <input name="plan" value="{{ old('plan', $tenant->plan) }}" class="w-full border rounded px-3 py-2 mt-1" />
        </label>
      </div>
      
      <!-- Configurazione lingua -->
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Lingue supportate</span>
          <input name="languages" value="{{ old('languages', implode(',', (array)($tenant->languages ?? []))) }}" class="w-full border rounded px-3 py-2 mt-1" placeholder="it,en,fr" />
          <small class="text-gray-600">Codici ISO separati da virgola (es: it,en,fr)</small>
        </label>
        <label class="block">
          <span class="text-sm font-medium">Lingua predefinita</span>
          <input name="default_language" value="{{ old('default_language', $tenant->default_language) }}" class="w-full border rounded px-3 py-2 mt-1" placeholder="it" />
        </label>
      </div>
      
      <!-- Prompts personalizzati -->
      <div class="border-t pt-4">
        <h3 class="font-medium mb-3">Prompts Personalizzati</h3>
        <div class="grid gap-4">
          <label class="block">
            <span class="text-sm font-medium">Prompt di sistema personalizzato</span>
            <textarea name="custom_system_prompt" rows="3" class="w-full border rounded px-3 py-2 mt-1" placeholder="Es: Sei un assistente specializzato per il customer service...">{{ old('custom_system_prompt', $tenant->custom_system_prompt) }}</textarea>
            <small class="text-gray-600">Definisce il comportamento del chatbot in ogni conversazione.</small>
          </label>
          <label class="block">
            <span class="text-sm font-medium">Template del contesto KB</span>
            <textarea name="custom_context_template" rows="2" class="w-full border rounded px-3 py-2 mt-1" placeholder="Es: Utilizza queste informazioni dalla knowledge base: {context}">{{ old('custom_context_template', $tenant->custom_context_template) }}</textarea>
            <small class="text-gray-600">Personalizza la presentazione del contesto. Usa {context} come placeholder.</small>
          </label>
        </div>
      </div>
      
      <div class="flex justify-end pt-4">
        <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salva Modifiche</button>
      </div>
    </form>
  </div>

  <!-- Impostazioni Intent -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Configurazione Intent</h2>
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4">
      @csrf @method('put')
      
      <div>
        <span class="text-sm font-medium mb-2 block">Intent Abilitati</span>
        <div class="grid md:grid-cols-4 gap-3">
          @php($intents = ['phone' => 'Telefono', 'email' => 'Email', 'address' => 'Indirizzo', 'schedule' => 'Orari'])
          @foreach($intents as $key => $label)
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="intents_enabled[{{ $key }}]" value="1" {{ old('intents_enabled.'.$key, data_get($tenant->intents_enabled, $key, true)) ? 'checked' : '' }} />
            <span class="text-sm">{{ $label }}</span>
          </label>
          @endforeach
        </div>
      </div>
      
      <label class="block">
        <span class="text-sm font-medium">Parole chiave aggiuntive (JSON)</span>
        <textarea name="extra_intent_keywords" rows="4" class="w-full border rounded px-3 py-2 mt-1 font-mono text-sm" placeholder='{"phone":["centralino"],"schedule":["ricevimento"]}'>{{ old('extra_intent_keywords', json_encode($tenant->extra_intent_keywords ?? new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) }}</textarea>
        <small class="text-gray-600">Dizionario JSON con array di parole chiave aggiuntive per ogni intent.</small>
      </label>
      
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Modalità scoping KB</span>
          <select name="kb_scope_mode" class="w-full border rounded px-3 py-2 mt-1">
            @php($mode = old('kb_scope_mode', $tenant->kb_scope_mode ?? 'relaxed'))
            <option value="relaxed" {{ $mode==='relaxed' ? 'selected' : '' }}>Relaxed (fallback su tenant se KB vuota)</option>
            <option value="strict" {{ $mode==='strict' ? 'selected' : '' }}>Strict (solo KB selezionata)</option>
          </select>
        </label>
        <label class="block">
          <span class="text-sm font-medium">Soglia intent (0–1)</span>
          <input type="number" name="intent_min_score" step="0.05" min="0" max="1" value="{{ old('intent_min_score', $tenant->intent_min_score ?? '') }}" class="w-full border rounded px-3 py-2 mt-1" placeholder="0.5" />
          <small class="text-gray-600">Lascia vuoto per soglia automatica</small>
        </label>
      </div>
      
      <div class="flex justify-end pt-2">
        <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salva Impostazioni Intent</button>
      </div>
    </form>
  </div>

  <!-- Knowledge Bases -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Knowledge Bases</h2>
    
    <!-- Form creazione KB -->
    <form method="post" action="{{ route('admin.tenants.kb.store', $tenant) }}" class="border-b pb-4 mb-4">
      @csrf
      <div class="grid md:grid-cols-4 gap-3 items-end">
        <label class="block">
          <span class="text-sm font-medium">Nome KB *</span>
          <input name="name" class="w-full border rounded px-3 py-2 mt-1" required />
        </label>
        <label class="block md:col-span-2">
          <span class="text-sm font-medium">Descrizione</span>
          <input name="description" class="w-full border rounded px-3 py-2 mt-1" />
        </label>
        <div class="flex items-center justify-between">
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_default" value="1" />
            <span class="text-sm">Default</span>
          </label>
          <button class="px-3 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700">Aggiungi</button>
        </div>
      </div>
    </form>
    
    <!-- Lista KB esistenti -->
    @php($kbs = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('id')->get())
    @if($kbs->isEmpty())
      <div class="text-sm text-gray-600 py-4">Nessuna Knowledge Base presente. Creane una per iniziare.</div>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 text-left">
              <th class="p-3 font-medium">ID</th>
              <th class="p-3 font-medium">Nome</th>
              <th class="p-3 font-medium">Descrizione</th>
              <th class="p-3 font-medium">Default</th>
              <th class="p-3 font-medium">Azioni</th>
            </tr>
          </thead>
          <tbody>
            @foreach($kbs as $kb)
              <tr class="border-t">
                <td class="p-3">{{ $kb->id }}</td>
                <td class="p-3">
                  <form method="post" action="{{ route('admin.tenants.kb.update', [$tenant, $kb]) }}" class="inline-flex items-center gap-2">
                    @csrf @method('put')
                    <input name="name" value="{{ $kb->name }}" class="border rounded px-2 py-1 text-sm min-w-32" />
                </td>
                <td class="p-3">
                    <input name="description" value="{{ $kb->description }}" class="border rounded px-2 py-1 text-sm w-full" />
                </td>
                <td class="p-3">
                    <label class="inline-flex items-center gap-1">
                      <input type="checkbox" name="is_default" value="1" {{ $kb->is_default ? 'checked' : '' }} />
                      <span class="text-xs">Default</span>
                    </label>
                </td>
                <td class="p-3">
                  <div class="flex gap-2">
                    <button class="px-2 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Salva</button>
                  </form>
                  <form method="post" action="{{ route('admin.tenants.kb.destroy', [$tenant, $kb]) }}" onsubmit="return confirm('Eliminare la KB? I documenti resteranno senza KB.')" class="inline">
                    @csrf @method('delete')
                    <button class="px-2 py-1 bg-rose-600 text-white rounded text-sm hover:bg-rose-700">Elimina</button>
                  </form>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</div>
@endsection

