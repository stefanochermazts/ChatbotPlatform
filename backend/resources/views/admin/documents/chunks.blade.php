@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Chunk – {{ $tenant->name }} / ID {{ $document->id }} – {{ $document->title }}</h1>

<div class="mb-4">
  <a href="{{ route('admin.documents.index', $tenant) }}" class="text-sm text-blue-600 hover:underline">← Torna ai documenti</a>
  @if(!empty($document->extracted_path))
    <span class="mx-2 text-gray-400">|</span>
    <a href="{{ \Storage::url($document->extracted_path) }}" target="_blank" class="text-sm text-emerald-600 hover:underline">Testo estratto (.md)</a>
  @endif
  <span class="mx-2 text-gray-400">|</span>
  <a href="{{ \Storage::url($document->path) }}" target="_blank" class="text-sm text-blue-600 hover:underline">File</a>
  <span class="mx-2 text-gray-400">|</span>
  <span class="text-sm">Chunk totali: {{ $chunks->count() }}</span>
  <button id="copyAll" class="ml-2 text-xs px-2 py-1 bg-gray-200 rounded">Copia tutto</button>
  <button id="downloadMd" class="ml-2 text-xs px-2 py-1 bg-gray-200 rounded">Scarica .md</button>
  <input id="search" type="text" placeholder="Cerca..." class="ml-2 border rounded px-2 py-1 text-xs" />
</div>

<div id="chunkContainer" class="space-y-4">
@forelse($chunks as $c)
  <div class="border rounded p-3 bg-white shadow-sm">
    <div class="text-xs text-gray-500 mb-2">Chunk #{{ $c->chunk_index }}</div>
    <pre class="whitespace-pre-wrap text-sm">{{ $c->content }}</pre>
  </div>
@empty
  <div class="text-sm text-gray-600">Nessun chunk trovato.</div>
@endforelse
</div>

<script>
document.getElementById('copyAll').addEventListener('click', function() {
  const texts = Array.from(document.querySelectorAll('#chunkContainer pre')).map(p => p.textContent);
  navigator.clipboard.writeText(texts.join('\n\n---\n\n'));
});
document.getElementById('downloadMd').addEventListener('click', function() {
  const texts = Array.from(document.querySelectorAll('#chunkContainer pre')).map(p => p.textContent);
  const blob = new Blob([texts.join('\n\n---\n\n')], {type: 'text/markdown'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'document-{{ $document->id }}-chunks.md'; a.click();
  URL.revokeObjectURL(url);
});
document.getElementById('search').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#chunkContainer > div').forEach(card => {
    const txt = card.textContent.toLowerCase();
    card.style.display = txt.includes(q) ? '' : 'none';
  });
});
</script>
@endsection


