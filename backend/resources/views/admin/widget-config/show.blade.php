@extends('admin.layout')

@section('title', 'Configurazione Widget - '.$tenant->name)

@section('content')
<div class="container mx-auto px-4 py-6">
  @include('admin.widget-config.partials.header')

  @include('admin.widget-config.partials.overview')

  <div class="bg-white rounded-lg shadow p-6 mt-6">
    <h2 class="text-xl font-semibold mb-4">🧠 Configurazione API</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div>
        <div class="mb-2 text-sm text-gray-600">Modello</div>
        <div class="text-gray-900">{{ $config->api_model ?? 'gpt-4o-mini' }}</div>
      </div>
      <div>
        <div class="mb-2 text-sm text-gray-600">Temperature</div>
        <div class="text-gray-900">{{ number_format($config->temperature ?? 0.3, 2) }}</div>
      </div>
      <div>
        <div class="mb-2 text-sm text-gray-600">Max Tokens</div>
        <div class="text-gray-900">{{ number_format($config->max_tokens ?? 1000) }}</div>
      </div>
      <div>
        <div class="mb-2 text-sm text-gray-600">API Key</div>
        @if($apiKey)
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">Presente</span>
        @else
          <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">Non trovata</span>
        @endif
      </div>
    </div>

    <form method="post" action="{{ route('admin.widget-config.test-api', $tenant) }}" class="mt-6 hidden"></form>

    <div class="mt-6 border-t pt-6">
      <h3 class="text-lg font-semibold mb-2">🔑 Salva/aggiorna API Key del widget</h3>
      <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="flex gap-3 items-end">
        @csrf
        @method('put')
        <div class="flex-1">
          <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
          <input type="password" name="api_key_plain" class="w-full border rounded-lg px-3 py-2" placeholder="sk-..." />
          <p class="text-xs text-gray-500 mt-1">Verrà salvata in modo sicuro per questo tenant e usata dallo snippet embed.</p>
        </div>
        <button class="btn btn-primary" type="submit">💾 Salva</button>
      </form>
    </div>
  </div>
</div>
@endsection
