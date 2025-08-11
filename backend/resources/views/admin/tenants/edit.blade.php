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
  <div>
    <button class="px-3 py-2 bg-blue-600 text-white rounded">Salva</button>
  </div>
</form>
@endsection

