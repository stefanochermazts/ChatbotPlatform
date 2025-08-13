@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Modifica Cliente</h1>
<form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="bg-white border rounded p-4 grid gap-3 max-w-lg">
  @csrf @method('put')
  <label class="block">
    <span class="text-sm">Nome</span>
    <input name="name" value="{{ old('name', $tenant->name) }}" class="w-full border rounded px-3 py-2" required />
  </label>
  <label class="block">
    <span class="text-sm">Slug</span>
    <input name="slug" value="{{ old('slug', $tenant->slug) }}" class="w-full border rounded px-3 py-2" required />
  </label>
  <label class="block">
    <span class="text-sm">Dominio (opz)</span>
    <input name="domain" value="{{ old('domain', $tenant->domain) }}" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Piano (opz)</span>
    <input name="plan" value="{{ old('plan', $tenant->plan) }}" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Lingue supportate (ISO, separate da virgola). Es: it,en,fr</span>
    <input name="languages" value="{{ old('languages', implode(',', (array)($tenant->languages ?? []))) }}" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Lingua predefinita</span>
    <input name="default_language" value="{{ old('default_language', $tenant->default_language) }}" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Prompt di sistema personalizzato (opzionale)</span>
    <textarea name="custom_system_prompt" rows="4" class="w-full border rounded px-3 py-2" placeholder="Es: Sei un assistente specializzato per il customer service. Rispondi sempre in modo cortese e professionale...">{{ old('custom_system_prompt', $tenant->custom_system_prompt) }}</textarea>
    <small class="text-gray-600">Questo messaggio di sistema verrà aggiunto a ogni conversazione per definire il comportamento del chatbot.</small>
  </label>
  <label class="block">
    <span class="text-sm">Template del contesto KB (opzionale)</span>
    <textarea name="custom_context_template" rows="3" class="w-full border rounded px-3 py-2" placeholder="Es: Utilizza queste informazioni dalla knowledge base per rispondere: {context}">{{ old('custom_context_template', $tenant->custom_context_template) }}</textarea>
    <small class="text-gray-600">Personalizza come viene presentato il contesto della knowledge base. Usa {context} come placeholder per il contenuto effettivo.</small>
  </label>
  <div>
    <button class="px-3 py-2 bg-blue-600 text-white rounded">Salva</button>
  </div>
</form>
@endsection

