@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Nuovo Cliente</h1>
<form method="post" action="{{ route('admin.tenants.store') }}" class="bg-white border rounded p-4 grid gap-3 max-w-lg">
  @csrf
  <label class="block">
    <span class="text-sm">Nome</span>
    <input name="name" class="w-full border rounded px-3 py-2" required />
  </label>
  <label class="block">
    <span class="text-sm">Slug</span>
    <input name="slug" class="w-full border rounded px-3 py-2" required />
  </label>
  <label class="block">
    <span class="text-sm">Dominio (opz)</span>
    <input name="domain" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Piano (opz)</span>
    <input name="plan" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Lingue supportate (ISO, separate da virgola). Es: it,en,fr</span>
    <input name="languages" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Lingua predefinita</span>
    <input name="default_language" class="w-full border rounded px-3 py-2" />
  </label>
  <label class="block">
    <span class="text-sm">Prompt di sistema personalizzato (opzionale)</span>
    <textarea name="custom_system_prompt" rows="4" class="w-full border rounded px-3 py-2" placeholder="Es: Sei un assistente specializzato per il customer service. Rispondi sempre in modo cortese e professionale..."></textarea>
    <small class="text-gray-600">Questo messaggio di sistema verrà aggiunto a ogni conversazione per definire il comportamento del chatbot.</small>
  </label>
  <label class="block">
    <span class="text-sm">Template del contesto KB (opzionale)</span>
    <textarea name="custom_context_template" rows="3" class="w-full border rounded px-3 py-2" placeholder="Es: Utilizza queste informazioni dalla knowledge base per rispondere: {context}"></textarea>
    <small class="text-gray-600">Personalizza come viene presentato il contesto della knowledge base. Usa {context} come placeholder per il contenuto effettivo.</small>
  </label>
  <div>
    <button class="px-3 py-2 bg-blue-600 text-white rounded">Crea</button>
  </div>
</form>
@endsection

