@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Documenti – {{ $tenant->name }}</h1>

<form method="get" action="" class="mb-4 flex flex-wrap items-center gap-2">
  <label class="text-sm">Filtra per KB:</label>
  <select name="kb_id" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
    <option value="">Tutte le KB</option>
    @php($kbOptions = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('name')->get())
    @foreach($kbOptions as $kb)
      <option value="{{ $kb->id }}" {{ (int)request('kb_id', (int)($kbId ?? 0)) === (int)$kb->id ? 'selected' : '' }}>{{ $kb->name }}</option>
    @endforeach
  </select>
  
  <label class="text-sm ml-4">Cerca in Source URL:</label>
  <input type="text" name="source_url" value="{{ request('source_url', $sourceUrlSearch ?? '') }}" 
         placeholder="es: comunesancesareo.it" 
         class="border rounded px-2 py-1 text-sm w-48" />

  <label class="text-sm ml-4">Qualità:</label>
  <select name="quality_filter" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
    @php($qf = request('quality_filter', ''))
    <option value="" {{ $qf==='' ? 'selected' : '' }}>Tutte</option>
    <option value="high" {{ $qf==='high' ? 'selected' : '' }}>Alta (≥0.7)</option>
    <option value="medium" {{ $qf==='medium' ? 'selected' : '' }}>Media (0.4-0.7)</option>
    <option value="low" {{ $qf==='low' ? 'selected' : '' }}>Bassa (<0.4)</option>
    <option value="no_analysis" {{ $qf==='no_analysis' ? 'selected' : '' }}>Senza Analisi</option>
  </select>

  <button type="submit" class="px-3 py-1 bg-blue-600 text-white rounded text-sm">Filtra</button>
  @if(request()->hasAny(['kb_id','source_url','quality_filter']) && (request('kb_id') || request('source_url') || request('quality_filter')))
    <a href="{{ route('admin.documents.index', $tenant) }}" class="text-xs text-gray-600 underline">Reset filtri</a>
  @endif
</form>

<!-- Uploader (caricamento + embeddings + ingestion) -->
<div x-data="uploader()" x-init="init()" class="mb-6">
  <div x-ref="dropzone" class="border-2 border-dashed rounded p-6 text-slate-600 bg-slate-50">
    <p class="mb-2">Trascina qui i file o</p>
    <button @click="chooseFiles" class="px-4 py-2 bg-indigo-600 text-white rounded">Scegli file</button>
    <input type="file" x-ref="fileInput" class="hidden" multiple @change="onFileChange">
  </div>
  <div class="mt-3 text-sm" x-show="files.length">
    <p class="font-medium">File selezionati (<span x-text="files.length"></span>)</p>
    <ul class="list-disc ml-6 max-h-40 overflow-auto">
      <template x-for="(f, i) in files" :key="i + '-' + (f.name || i)">
        <li x-text="f.name || ('file_' + i)"></li>
      </template>
    </ul>
    <div class="mt-3 flex items-center gap-3">
      <label class="text-sm">KB target:</label>
      <select x-model="kbTarget" class="border rounded px-2 py-1 text-sm">
        <option value="">Default</option>
        @foreach($kbOptions as $kb)
          <option value="{{ $kb->id }}">{{ $kb->name }}</option>
        @endforeach
      </select>
      <button @click="upload" :disabled="uploading" class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-50">Carica & Ingest</button>
      <span x-show="uploading">Caricamento in corso...</span>
    </div>
    <div class="mt-3 text-red-600" x-show="errors.length">
      <p class="font-medium">Errori:</p>
      <ul class="list-disc ml-6">
        <template x-for="(e, j) in errors" :key="'err-'+j">
          <li x-text="e"></li>
        </template>
      </ul>
    </div>
  </div>
</div>

<!-- Batch re-scrape (compatibile) -->
<div class="bg-white border rounded p-4 mb-4">
  <h2 class="text-base font-semibold mb-2">🔄 Re-scraping Batch</h2>
  <form method="post" action="{{ route('admin.documents.rescrape-all', $tenant) }}" onsubmit="return confirm('Eseguire il re-scrape di tutti i documenti filtrati con source_url?')">
    @csrf
    <input type="hidden" name="confirm" value="1" />
    <input type="hidden" name="kb_id" value="{{ (int)request('kb_id', (int)($kbId ?? 0)) }}" />
    <input type="hidden" name="source_url" value="{{ request('source_url', $sourceUrlSearch ?? '') }}" />
    <button class="px-3 py-1 bg-orange-600 text-white rounded text-sm">Re-scrape documenti filtrati con source_url</button>
    <p class="text-xs text-gray-600 mt-1">Applicherà i filtri KB/URL correnti; ignora il filtro qualità.</p>
  </form>
</div>

<!-- Cancellazione per KB selezionata -->
<div class="bg-white border rounded p-4 mb-4">
  <h2 class="text-base font-semibold mb-2">🗑️ Cancella documenti per KB</h2>
  <form method="post" action="{{ route('admin.documents.destroyByKb', $tenant) }}" onsubmit="return confirm('Confermi l\'eliminazione DEFINITIVA di tutti i documenti della KB selezionata? Operazione irreversibile.')">
    @csrf @method('delete')
    <div class="flex items-center gap-2">
      <select name="knowledge_base_id" class="border rounded px-2 py-1 text-sm">
        <option value="">Seleziona KB</option>
        @foreach($kbOptions as $kb)
          <option value="{{ $kb->id }}">{{ $kb->name }}</option>
        @endforeach
      </select>
      <button class="px-3 py-1 bg-red-600 text-white rounded text-sm">Cancella tutti i documenti della KB</button>
    </div>
    <p class="text-xs text-gray-600 mt-1">Suggerimento: filtra prima per KB per verificare l\'impatto.</p>
  </form>
</div>

<div class="bg-white border rounded overflow-x-auto">
  <div class="px-4 py-2 bg-gray-50 border-b text-sm text-gray-600">
    Trovati {{ $docs->total() }} document{{ $docs->total() !== 1 ? 'i' : 'o' }}
  </div>
  <table class="w-full text-sm min-w-[1200px]">
    <thead>
      <tr class="bg-gray-100 text-left">
        <th class="p-2">ID</th>
        <th class="p-2">Titolo</th>
        <th class="p-2">KB</th>
        <th class="p-2">Stato</th>
        <th class="p-2">Progress</th>
        <th class="p-2">Errore</th>
        <th class="p-2">Sorgente</th>
        <th class="p-2">Ultimo scraping</th>
        <th class="p-2">Qualità</th>
        <th class="p-2">Path</th>
        <th class="p-2">Source URL</th>
        <th class="p-2">Azioni</th>
      </tr>
    </thead>
    <tbody>
      @forelse($docs as $d)
      <tr class="border-t align-top">
        <td class="p-2">{{ $d->id }}</td>
        <td class="p-2">{{ $d->title }}</td>
        <td class="p-2">{{ optional($d->knowledgeBase)->name ?? '-' }}</td>
        <td class="p-2">{{ $d->ingestion_status }}</td>
        <td class="p-2">
          <div class="w-44 bg-gray-200 rounded h-2">
            <div class="bg-emerald-500 h-2 rounded" style="width: {{ (int)($d->ingestion_progress ?? 0) }}%"></div>
          </div>
          <div class="text-xs text-gray-600 mt-1">{{ (int)($d->ingestion_progress ?? 0) }}%</div>
        </td>
        <td class="p-2">
          @if($d->last_error)
            <pre class="text-xs whitespace-pre-wrap max-w-xs">{{ $d->last_error }}</pre>
          @else
            <span class="text-gray-400 text-xs">-</span>
          @endif
        </td>
        <td class="p-2">{{ $d->source }}</td>
        <td class="p-2">
          @if($d->last_scraped_at)
            <span class="text-xs">{{ $d->last_scraped_at->format('Y-m-d H:i') }}</span>
          @else
            <span class="text-gray-400 text-xs">-</span>
          @endif
        </td>
        <td class="p-2">
          @php($qa = is_array($d->metadata ?? null) ? ($d->metadata['quality_analysis'] ?? null) : null)
          @if($qa && isset($qa['quality_score']))
            <span class="text-xs font-mono">{{ number_format((float)$qa['quality_score'], 2) }}</span>
          @else
            <span class="text-gray-400 text-xs">n/a</span>
          @endif
        </td>
        <td class="p-2">
          @if($d->path)
            <a href="{{ \Storage::url($d->path) }}" target="_blank" class="text-blue-600 hover:underline text-xs" title="Visualizza contenuto processato">
              {{ \Illuminate\Support\Str::limit($d->path, 40) }}
            </a>
          @else
            <span class="text-gray-400 text-xs">-</span>
          @endif
        </td>
        <td class="p-2">
          @if($d->source_url)
            <a href="{{ $d->source_url }}" target="_blank" class="text-blue-600 hover:underline text-xs" title="{{ $d->source_url }}">
              {{ \Illuminate\Support\Str::limit($d->source_url, 50) }}
            </a>
          @else
            <span class="text-gray-400 text-xs">-</span>
          @endif
        </td>
        <td class="p-2">
          <div class="flex flex-col gap-1">
            @if($d->ingestion_status === 'failed')
            <form class="inline" method="post" action="{{ route('admin.documents.retry', [$tenant, $d]) }}">
              @csrf
              <button class="px-2 py-1 text-xs bg-amber-500 text-white rounded w-full">Riprova</button>
            </form>
            @endif

            @if($d->source_url)
            <form class="inline" method="post" action="{{ route('admin.documents.rescrape', $d) }}" onsubmit="return confirm('Eseguire un re-scrape di questo documento?')">
              @csrf
              <button class="px-2 py-1 text-xs bg-purple-600 text-white rounded w-full">🔄 Re-scrape</button>
            </form>
            @endif

            <form class="inline" method="post" action="{{ route('admin.tenants.documents.bulk-assign-kb', $tenant) }}">
              @csrf
              <input type="hidden" name="document_ids" value="{{ $d->id }}" />
              <div class="flex gap-1">
                <select name="knowledge_base_id" class="border rounded px-1 py-1 text-xs flex-1">
                  <option value="">KB default</option>
                  @foreach(\App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('name')->get() as $kb)
                    <option value="{{ $kb->id }}">{{ $kb->name }}</option>
                  @endforeach
                </select>
                <button class="px-2 py-1 text-xs bg-indigo-600 text-white rounded">✓</button>
              </div>
            </form>

            <form class="inline" method="post" action="{{ route('admin.documents.destroy', [$tenant, $d]) }}" onsubmit="return confirm('Eliminare definitivamente?')">
              @csrf @method('delete')
              <button class="px-2 py-1 text-xs bg-rose-600 text-white rounded w-full">Elimina</button>
            </form>
          </div>
        </td>
      </tr>
      @empty
      <tr class="border-t"><td colspan="11" class="p-3 text-sm text-gray-600">Nessun documento trovato.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<div class="mt-4">{{ $docs->links() }}</div>

<script>
function uploader() {
  return {
    uploading: false,
    files: [],
    errors: [],
    kbTarget: '{{ (int)request('kb_id', (int)($kbId ?? 0)) ?: '' }}',
    fileKeys: new Set(),
    sign(f) { return [f.name || '', f.size || 0, f.lastModified || 0].join(':'); },
    init() {
      const dz = this.$refs.dropzone;
      dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
      dz.addEventListener('dragleave', () => { dz.classList.remove('dragover'); });
      dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('dragover'); this.handleFiles(e.dataTransfer.files); });
    },
    chooseFiles() { this.$refs.fileInput.click(); },
    onFileChange(e) { this.handleFiles(e.target.files); },
    handleFiles(list) {
      const arr = Array.from(list || []);
      for (const f of arr) {
        const key = this.sign(f);
        if (!this.fileKeys.has(key)) {
          this.fileKeys.add(key);
          this.files.push(f);
        }
      }
    },
    clear() { this.files = []; this.fileKeys.clear(); this.errors = []; },
    async upload() {
      if (this.files.length === 0) return;
      this.uploading = true; this.errors = [];
      const form = new FormData();
      this.files.forEach(f => form.append('files[]', f));
      if (this.kbTarget) { form.append('knowledge_base_id', this.kbTarget); }
      const csrf = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '{{ csrf_token() }}';
      const res = await fetch('{{ route('admin.documents.upload', $tenant) }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: form
      });
      if (!res.ok) {
        const msg = await res.text(); this.errors.push(msg);
      } else {
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (data && data.errors && data.errors.length) { data.errors.forEach(e => this.errors.push(`${e.name || e.index}: ${e.error}`)); }
        this.clear();
        window.location.reload();
      }
      this.uploading = false;
    }
  };
}
</script>
@endsection
