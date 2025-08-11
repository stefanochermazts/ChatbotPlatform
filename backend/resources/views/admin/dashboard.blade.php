@extends('admin.layout')

@section('content')
<div class="grid gap-4">
  <div class="bg-white border rounded p-4">
    <h2 class="font-semibold mb-2">Panoramica</h2>
    <p>Clienti totali: <strong>{{ $tenantCount }}</strong></p>
  </div>
  <div class="flex gap-3">
    <a href="{{ route('admin.tenants.index') }}" class="px-3 py-2 bg-blue-600 text-white rounded">Gestisci Clienti</a>
    <a href="{{ route('admin.rag.index') }}" class="px-3 py-2 bg-indigo-600 text-white rounded">RAG Tester</a>
  </div>
</div>
@endsection

