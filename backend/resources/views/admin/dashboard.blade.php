@extends('admin.layout')

@section('content')
<div class="grid gap-4">
  <div class="bg-white border rounded p-4">
    <h2 class="font-semibold mb-2">Panoramica</h2>
    <p>Clienti totali: <strong>{{ $tenantCount }}</strong></p>
  </div>
  <div class="bg-white border rounded p-4">
    <h2 class="font-semibold mb-2">Configurazione RAG attiva</h2>
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <div>
        <h3 class="font-medium mb-1">Feature flags</h3>
        <pre class="bg-gray-50 border rounded p-2">{{ json_encode($rag['features'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      <div>
        <h3 class="font-medium mb-1">Hybrid</h3>
        <pre class="bg-gray-50 border rounded p-2">{{ json_encode($rag['hybrid'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      <div>
        <h3 class="font-medium mb-1">Reranker</h3>
        <pre class="bg-gray-50 border rounded p-2">{{ json_encode($rag['reranker'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      <div>
        <h3 class="font-medium mb-1">Multi‑query</h3>
        <pre class="bg-gray-50 border rounded p-2">{{ json_encode($rag['multiquery'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      <div>
        <h3 class="font-medium mb-1">Context</h3>
        <pre class="bg-gray-50 border rounded p-2">{{ json_encode($rag['context'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      <div>
        <h3 class="font-medium mb-1">Cache / Telemetry</h3>
        <pre class="bg-gray-50 border rounded p-2">{{ json_encode(['cache'=>$rag['cache'] ?? [], 'telemetry'=>$rag['telemetry'] ?? []], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
    </div>
  </div>
  <div class="flex gap-3">
    <a href="{{ route('admin.tenants.index') }}" class="px-3 py-2 bg-blue-600 text-white rounded">Gestisci Clienti</a>
    <a href="{{ route('admin.rag.index') }}" class="px-3 py-2 bg-indigo-600 text-white rounded">RAG Tester</a>
  </div>
</div>
@endsection

