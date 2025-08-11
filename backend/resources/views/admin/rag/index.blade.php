@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">RAG Tester</h1>
<form method="post" action="{{ route('admin.rag.run') }}" class="bg-white border rounded p-4 grid gap-3">
  @csrf
  <div class="grid md:grid-cols-3 gap-3">
    <label class="block">
      <span class="text-sm">Cliente</span>
      <select name="tenant_id" class="w-full border rounded px-3 py-2" required>
        <option value="">Seleziona...</option>
        @foreach($tenants as $t)
          <option value="{{ $t->id }}" @selected(($tenant_id ?? null)===$t->id)>{{ $t->name }} ({{ $t->id }})</option>
        @endforeach
      </select>
    </label>
    <label class="block">
      <span class="text-sm">Top K (chunks)</span>
      <input type="number" name="top_k" min="1" max="50" value="{{ old('top_k', 20) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <label class="block">
      <span class="text-sm">MMR λ (0-1)</span>
      <input type="number" name="mmr_lambda" step="0.05" min="0" max="1" value="{{ old('mmr_lambda', 0.3) }}" class="w-full border rounded px-3 py-2" />
    </label>
  </div>
  <label class="block">
    <span class="text-sm">Query</span>
    <textarea name="query" rows="3" class="w-full border rounded px-3 py-2" required>{{ $query ?? '' }}</textarea>
  </label>
  <label class="inline-flex items-center gap-2">
    <input type="checkbox" name="with_answer" value="1" /> Genera risposta con LLM
  </label>
  <div>
    <button class="px-3 py-2 bg-indigo-600 text-white rounded">Esegui</button>
  </div>
</form>

@if(isset($result))
  <div class="mt-6 grid gap-4">
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Citazioni & Snippet</h2>
      @if(empty($result['citations']))
        <p class="text-sm text-gray-600">Nessuna citazione trovata.</p>
      @else
        <ol class="list-decimal list-inside text-sm grid gap-3">
          @foreach($result['citations'] as $c)
            <li>
              <div class="mb-1">
                <a class="text-blue-600 underline" href="{{ $c['url'] }}" target="_blank">{{ $c['title'] ?? ('Doc '.$c['id']) }}</a>
              </div>
              @if(!empty($c['snippet']))
                <blockquote class="p-2 bg-gray-50 border rounded">{{ $c['snippet'] }}</blockquote>
              @endif
            </li>
          @endforeach
        </ol>
      @endif
    </div>
    @if(($result['answer'] ?? null) !== null)
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Risposta</h2>
      <pre class="whitespace-pre-wrap text-sm">{{ $result['answer'] }}

Fonti:
@foreach(($result['citations'] ?? []) as $c)- {{ $c['title'] ?? ('Doc '.$c['id']) }} ({{ $c['url'] }})
@endforeach</pre>
    </div>
    @endif
  </div>
@endif
@endsection

