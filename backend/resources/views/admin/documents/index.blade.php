@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Documenti – {{ $tenant->name }}</h1>
<form method="get" action="" class="mb-4 flex flex-wrap items-center gap-2">
  <label class="text-sm">Filtra per KB:</label>
  <select name="kb_id" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
    <option value="">Tutte le KB</option>
    @php($kbOptions = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('name')->get())
    @foreach($kbOptions as $kb)
      <option value="{{ $kb->id }}" @selected(($kbId ?? 0) == $kb->id)>{{ $kb->name }}</option>
    @endforeach
  </select>
  
  <label class="text-sm ml-4">Cerca in Source URL:</label>
  <input type="text" name="source_url" value="{{ $sourceUrlSearch ?? '' }}" 
         placeholder="es: comunesancesareo.it" 
         class="border rounded px-2 py-1 text-sm w-48" />
  
  <button type="submit" class="px-3 py-1 bg-blue-600 text-white rounded text-sm">Filtra</button>
  
  @if(($kbId ?? 0) > 0 || !empty($sourceUrlSearch ?? ''))
    <a href="{{ route('admin.documents.index', $tenant) }}" class="text-xs text-gray-600 underline">Reset filtri</a>
  @endif
</form>

@if(($kbId ?? 0) > 0 || !empty($sourceUrlSearch ?? ''))
<div class="mb-4 flex items-center gap-2">
  <span class="text-xs text-gray-600">Filtri attivi:</span>
  @if(($kbId ?? 0) > 0)
    @php($selectedKb = \App\Models\KnowledgeBase::find($kbId))
    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
      KB: {{ $selectedKb?->name ?? "ID $kbId" }}
    </span>
  @endif
  @if(!empty($sourceUrlSearch ?? ''))
    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
      URL: "{{ $sourceUrlSearch }}"
    </span>
  @endif
</div>
@endif

<!-- 🎯 NUOVA SEZIONE: Scraping Singolo URL -->
<div class="bg-white border rounded p-4 mb-6">
  <h2 class="text-lg font-semibold mb-4">🎯 Scraping Singolo URL</h2>
  <form x-data="singleUrlScraper()" @submit.prevent="submitForm" class="flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-64">
      <label class="block text-sm font-medium text-gray-700 mb-1">URL da scrapare</label>
      <input type="url" x-model="url" required 
             placeholder="https://esempio.com/pagina" 
             class="w-full border rounded px-3 py-2 text-sm" />
    </div>
    <div class="min-w-40">
      <label class="block text-sm font-medium text-gray-700 mb-1">Knowledge Base</label>
      <select x-model="knowledgeBaseId" class="border rounded px-3 py-2 text-sm">
        <option value="">Default</option>
        @foreach(\App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('name')->get() as $kb)
          <option value="{{ $kb->id }}">{{ $kb->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="flex items-center gap-2">
      <label class="flex items-center text-sm">
        <input type="checkbox" x-model="force" class="mr-1" />
        Force (sovrascrive esistenti)
      </label>
    </div>
    <button type="submit" :disabled="loading" 
            class="px-4 py-2 bg-green-600 text-white rounded text-sm hover:bg-green-700 disabled:opacity-50">
      <span x-show="!loading">🎯 Scrape URL</span>
      <span x-show="loading">⏳ Scraping...</span>
    </button>
  </form>
  
  <!-- Risultati -->
  <div x-show="result" x-data class="mt-4 p-3 rounded" :class="result?.success ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'">
    <div x-show="result?.success" class="text-green-800">
      <h4 class="font-medium">✅ Scraping completato!</h4>
      <p class="text-sm mt-1" x-text="result.message"></p>
      <div x-show="result.data" class="text-xs mt-2">
        <span>Documenti salvati: </span><span x-text="result.data?.saved_count"></span> | 
        <span>Nuovi: </span><span x-text="result.data?.stats?.new"></span> | 
        <span>Aggiornati: </span><span x-text="result.data?.stats?.updated"></span>
      </div>
    </div>
    <div x-show="!result?.success" class="text-red-800">
      <h4 class="font-medium">❌ Scraping fallito</h4>
      <p class="text-sm mt-1" x-text="result?.message"></p>
      <div x-show="result?.existing_document" class="text-xs mt-2 p-2 bg-red-100 rounded">
        <p><strong>Documento esistente:</strong></p>
        <p>ID: <span x-text="result.existing_document?.id"></span></p>
        <p>Titolo: <span x-text="result.existing_document?.title"></span></p>
        <p class="mt-1 text-red-600">💡 Usa "Force" per sovrascrivere</p>
      </div>
    </div>
  </div>
</div>

<div class="bg-white border rounded p-4 mb-6">
  <div x-data="uploader()" x-init="init()">
    <div x-ref="dropzone" class="border-2 border-dashed rounded p-6 text-gray-600 flex flex-col items-center justify-center">
      <p class="mb-2">Trascina qui i file o</p>
      <div class="flex items-center gap-2">
        <label class="px-3 py-2 bg-gray-100 rounded cursor-pointer">
          <input type="file" x-ref="fileInput" class="hidden" multiple accept=".pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx" @change="onFileChange">
          Scegli file
        </label>
        <select x-model.number="targetKbId" class="border rounded px-2 py-2 text-sm">
          <option value="">KB di default</option>
          @php($kbOptions = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('name')->get())
          @foreach($kbOptions as $kb)
            <option value="{{ $kb->id }}">{{ $kb->name }}</option>
          @endforeach
        </select>
        <button @click="startUploads" :disabled="uploading || items.length===0" class="px-3 py-2 bg-blue-600 text-white rounded disabled:opacity-50">Carica & Ingest</button>
      </div>
      <p class="text-xs mt-2" x-show="items.length">Selezionati: <span x-text="items.length"></span></p>
    </div>

    <div class="mt-4 space-y-3" x-show="items.length">
      <template x-for="it in items" :key="it.id">
        <div class="border rounded p-3">
          <div class="flex justify-between text-sm">
            <div class="font-medium" x-text="it.name"></div>
            <div x-text="it.status"></div>
          </div>
          <div class="mt-2 bg-gray-200 h-2 rounded overflow-hidden">
            <div class="bg-emerald-500 h-2" :style="`width: ${it.progress}%`"></div>
          </div>
          <div class="text-xs text-gray-600 mt-1" x-text="it.progress + '%'" ></div>
          <div class="text-xs text-rose-600 mt-1" x-show="it.error" x-text="it.error"></div>
        </div>
      </template>
    </div>

    <template x-if="errors.length">
      <div class="mt-3 text-red-600 text-sm">
        <template x-for="e in errors" :key="e"><div x-text="e"></div></template>
      </div>
    </template>
  </div>
  <div class="mt-3">
    <form method="post" action="{{ route('admin.documents.destroyAll', $tenant) }}" onsubmit="return confirm('Cancellare TUTTI i file e i vettori su Milvus per questo tenant? Operazione irreversibile.')">
      @csrf @method('delete')
      <button class="px-3 py-2 text-sm bg-rose-600 text-white rounded">Cancella tutti i file</button>
    </form>
    <form method="post" action="{{ route('admin.documents.destroyByKb', $tenant) }}" class="mt-2 flex items-center gap-2" onsubmit="return confirm('Cancellare tutti i documenti per la KB selezionata? Operazione irreversibile.')">
      @csrf @method('delete')
      <select name="knowledge_base_id" class="border rounded px-2 py-1 text-sm" required>
        <option value="">Seleziona KB…</option>
        @foreach(\App\Models\KnowledgeBase::where('tenant_id',$tenant->id)->orderBy('name')->get() as $kb)
          <option value="{{ $kb->id }}">{{ $kb->name }}</option>
        @endforeach
      </select>
      <button class="px-3 py-2 text-sm bg-rose-500 text-white rounded">Cancella file della KB</button>
    </form>
  </div>
</div>
<script>
  function uploader(){
    return {
      items:[], errors:[], uploading:false, concurrency:3, inflight:0, queueIndex:0, targetKbId:null, seen:{},
      init(){
        const dz=this.$refs.dropzone;
        dz.addEventListener('dragover',e=>{ e.preventDefault(); e.stopPropagation(); dz.classList.add('bg-indigo-50'); });
        dz.addEventListener('dragleave',()=>dz.classList.remove('bg-indigo-50'));
        dz.addEventListener('drop',e=>{ e.preventDefault(); e.stopPropagation(); dz.classList.remove('bg-indigo-50'); if(e.dataTransfer && e.dataTransfer.files){ this.handle(Array.from(e.dataTransfer.files)); }});
      },
      onFileChange(e){ this.handle(Array.from(e.target.files)); e.target.value = null; },
      handle(list){
        list.forEach(f=>{
          const key = [f.name, f.size, f.lastModified].join('::');
          if(this.seen[key]){ return; }
          this.seen[key] = true;
          this.items.push({ id: crypto.randomUUID(), file:f, name:f.name, progress:0, status:'queued', error:null, _key:key });
        });
      },
      startUploads(){
        if(this.uploading) return; this.uploading=true; this.errors=[]; this.queueIndex=0; this.inflight=0;
        const pump = () => {
          while(this.inflight < this.concurrency && this.queueIndex < this.items.length){
            const it=this.items[this.queueIndex++];
            if(it.status==='queued') this.uploadOne(it).then(()=>pump());
          }
          if(this.inflight===0 && this.queueIndex>=this.items.length){
            // tutte completate
            setTimeout(()=>window.location.reload(), 600);
          }
        };
        pump();
      },
      uploadOne(it){
        return new Promise((resolve)=>{
          this.inflight++; it.status='uploading'; it.progress=0;
          const form=new FormData(); form.append('files[]', it.file); if(this.targetKbId){ form.append('knowledge_base_id', this.targetKbId); }
          const xhr=new XMLHttpRequest();
          xhr.open('POST', '{{ route('admin.documents.upload', $tenant) }}', true);
          xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');
          xhr.setRequestHeader('Accept', 'application/json');
          xhr.upload.onprogress=(e)=>{ if(e.lengthComputable){ it.progress = Math.min(99, Math.round((e.loaded/e.total)*100)); } };
          xhr.onerror=()=>{ it.status='error'; it.error='Errore di rete'; this.inflight--; resolve(); };
          xhr.onload=()=>{
            if(xhr.status>=200 && xhr.status<300){ it.progress=100; it.status='done'; }
            else { it.status='error'; it.error = (xhr.responseText||'Errore'); }
            this.inflight--; resolve();
          };
          xhr.send(form);
        });
      }
    }
  }
</script>
<div class="bg-white border rounded overflow-x-auto">
  <div class="px-4 py-2 bg-gray-50 border-b text-sm text-gray-600">
    Trovati {{ $docs->total() }} document{{ $docs->total() !== 1 ? 'i' : 'o' }}
    @if(($kbId ?? 0) > 0 || !empty($sourceUrlSearch ?? ''))
      con i filtri applicati
    @endif
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
        <th class="p-2">Path</th>
        <th class="p-2">Source URL</th>
        <th class="p-2">Azioni</th>
      </tr>
    </thead>
    <tbody>
      @foreach($docs as $d)
      <tr class="border-t align-top">
        <td class="p-2">{{ $d->id }}</td>
        <td class="p-2">{{ $d->title }}</td>
        <td class="p-2">@php($kb = $d->knowledgeBase) {{ $kb?->name ?? '-' }}</td>
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
          @endif
        </td>
        <td class="p-2">{{ $d->source }}</td>
        <td class="p-2">
          @if($d->path)
            <a href="{{ \Storage::url($d->path) }}" target="_blank" class="text-blue-600 hover:underline text-xs" title="Visualizza contenuto processato">
              {{ Str::limit($d->path, 40) }}
            </a>
          @else
            <span class="text-gray-400 text-xs">-</span>
          @endif
        </td>
        <td class="p-2">
          @if($d->source_url)
            <a href="{{ $d->source_url }}" target="_blank" class="text-blue-600 hover:underline text-xs" title="{{ $d->source_url }}">
              {{ Str::limit($d->source_url, 50) }}
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
            <!-- 🔄 NUOVO: Pulsante Re-scrape -->
            <button onclick="rescrapeDocument({{ $d->id }}, '{{ addslashes($d->title) }}')" 
                    class="px-2 py-1 text-xs bg-purple-600 text-white rounded w-full hover:bg-purple-700">
              🔄 Re-scrape
            </button>
            @endif
            
            <form class="inline" method="post" action="{{ route('admin.tenants.documents.bulk-assign-kb', $tenant) }}">
              @csrf
              <input type="hidden" name="document_ids" value="{{ $d->id }}" />
              <div class="flex gap-1">
                <select name="knowledge_base_id" class="border rounded px-1 py-1 text-xs flex-1">
                  <option value="">KB default</option>
                  @foreach(\App\Models\KnowledgeBase::where('tenant_id',$tenant->id)->orderBy('name')->get() as $kb)
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
      @endforeach
    </tbody>
  </table>
</div>

<!-- 🔄 NUOVA SEZIONE: Re-scraping Batch -->
@php($scrapedDocsCount = $docs->filter(fn($d) => !empty($d->source_url))->count())
@if($scrapedDocsCount > 0)
<div class="mt-4 bg-white border rounded p-4">
  <h3 class="text-lg font-semibold mb-3">🔄 Re-scraping in Batch</h3>
  <div class="flex items-center gap-3">
    <p class="text-sm text-gray-600">
      Trovati <strong>{{ $scrapedDocsCount }}</strong> documenti con source_url 
      @if(($kbId ?? 0) > 0 || !empty($sourceUrlSearch ?? ''))
        (con filtri applicati)
      @endif
    </p>
    <button onclick="rescrapeAllDocuments()" 
            class="px-4 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700">
      🔄 Re-scrape Tutti i Documenti Scraped
    </button>
  </div>
  
  <!-- Progress per batch re-scraping -->
  <div id="batchProgress" style="display: none;" class="mt-4">
    <div class="bg-gray-200 rounded h-2">
      <div id="batchProgressBar" class="bg-orange-500 h-2 rounded" style="width: 0%"></div>
    </div>
    <p id="batchProgressText" class="text-sm text-gray-600 mt-1">Preparazione...</p>
  </div>
  
  <!-- Risultati batch -->
  <div id="batchResult" style="display: none;" class="mt-4 p-3 rounded"></div>
</div>
@endif

<div class="mt-4">{{ $docs->links() }}</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Focus automatico sul campo di ricerca se ha un valore
  const sourceUrlInput = document.querySelector('input[name="source_url"]');
  if (sourceUrlInput && sourceUrlInput.value.trim()) {
    sourceUrlInput.focus();
    sourceUrlInput.setSelectionRange(sourceUrlInput.value.length, sourceUrlInput.value.length);
  }
  
  // Doppio click per pulire il campo
  if (sourceUrlInput) {
    sourceUrlInput.addEventListener('dblclick', function() {
      this.value = '';
      this.focus();
    });
  }
  
  // Submit con Enter (già funziona nativamente, ma aggiungiamo feedback visivo)
  const filterForm = sourceUrlInput?.closest('form');
  if (filterForm) {
    filterForm.addEventListener('submit', function() {
      const submitBtn = this.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.textContent = 'Filtrando...';
        submitBtn.disabled = true;
      }
    });
  }
});

// 🎯 NUOVE FUNZIONI: Scraping e Re-scraping

// Alpine.js component per scraping singolo URL
function singleUrlScraper() {
  return {
    url: '',
    knowledgeBaseId: '',
    force: false,
    loading: false,
    result: null,
    
    async submitForm() {
      if (!this.url) return;
      
      this.loading = true;
      this.result = null;
      
      try {
        const formData = new FormData();
        formData.append('tenant_id', {{ $tenant->id }});
        formData.append('url', this.url);
        formData.append('force', this.force ? '1' : '0');
        if (this.knowledgeBaseId) {
          formData.append('knowledge_base_id', this.knowledgeBaseId);
        }
        
        const response = await fetch('{{ route('admin.scraper.single-url') }}', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
          },
          body: formData
        });
        
        this.result = await response.json();
        
        if (this.result.success) {
          setTimeout(() => {
            window.location.reload();
          }, 2000);
        }
        
      } catch (error) {
        this.result = {
          success: false,
          message: 'Errore di rete: ' + error.message
        };
      } finally {
        this.loading = false;
      }
    }
  }
}

// Funzione per re-scraping di un singolo documento
async function rescrapeDocument(documentId, title) {
  if (!confirm(`Re-scrapare il documento "${title}"?\n\nQuesta operazione aggiornerà il contenuto dal source URL originale.`)) {
    return;
  }
  
  const button = event.target;
  const originalText = button.innerHTML;
  button.innerHTML = '⏳ Re-scraping...';
  button.disabled = true;
  
  try {
    const response = await fetch(`/admin/documents/${documentId}/rescrape`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': '{{ csrf_token() }}',
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      }
    });
    
    const result = await response.json();
    
    if (result.success) {
      alert('✅ Re-scraping completato!\\n\\n' + result.message);
      window.location.reload();
    } else {
      alert('❌ Re-scraping fallito:\\n\\n' + result.message);
    }
    
  } catch (error) {
    alert('💥 Errore durante il re-scraping:\\n\\n' + error.message);
  } finally {
    button.innerHTML = originalText;
    button.disabled = false;
  }
}

// Funzione per re-scraping di tutti i documenti
async function rescrapeAllDocuments() {
  const scrapedCount = {{ $scrapedDocsCount }};
  
  if (!confirm(`Re-scrapare TUTTI i ${scrapedCount} documenti con source_url?\\n\\n⚠️ ATTENZIONE: Questa operazione può richiedere molto tempo e aggiornerà tutti i documenti scraped.\\n\\nSei sicuro di voler procedere?`)) {
    return;
  }
  
  const button = event.target;
  const originalText = button.innerHTML;
  button.innerHTML = '⏳ Avvio...';
  button.disabled = true;
  
  // Mostra progress bar
  document.getElementById('batchProgress').style.display = 'block';
  document.getElementById('batchResult').style.display = 'none';
  
  const progressBar = document.getElementById('batchProgressBar');
  const progressText = document.getElementById('batchProgressText');
  
  progressText.textContent = 'Preparazione batch re-scraping...';
  
  try {
    const response = await fetch('{{ route('admin.documents.rescrape-all', $tenant) }}', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': '{{ csrf_token() }}',
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        confirm: true
      })
    });
    
    const result = await response.json();
    
    // Anima progress bar
    progressBar.style.width = '100%';
    progressText.textContent = 'Completato!';
    
    // Mostra risultati
    setTimeout(() => {
      const resultDiv = document.getElementById('batchResult');
      resultDiv.style.display = 'block';
      resultDiv.className = `mt-4 p-3 rounded ${result.success ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'}`;
      
      let html = `<h4 class="font-medium">${result.success ? '✅' : '❌'} ${result.message}</h4>`;
      
      if (result.data) {
        html += `
          <div class="text-sm mt-2">
            <p>📊 Statistiche:</p>
            <ul class="list-disc list-inside ml-2">
              <li>Totale documenti: ${result.data.total_documents}</li>
              <li>Successi: ${result.data.success_count}</li>
              <li>Fallimenti: ${result.data.failure_count}</li>
            </ul>
          </div>
        `;
        
        if (result.data.errors && result.data.errors.length > 0) {
          html += `
            <div class="text-xs mt-2">
              <p>Errori (primi 10):</p>
              <ul class="list-disc list-inside ml-2">
                ${result.data.errors.map(error => `<li>${error}</li>`).join('')}
              </ul>
            </div>
          `;
        }
      }
      
      resultDiv.innerHTML = html;
      
      // Ricarica pagina se successo
      if (result.success) {
        setTimeout(() => {
          window.location.reload();
        }, 3000);
      }
    }, 1000);
    
  } catch (error) {
    progressText.textContent = 'Errore durante il batch re-scraping';
    
    const resultDiv = document.getElementById('batchResult');
    resultDiv.style.display = 'block';
    resultDiv.className = 'mt-4 p-3 rounded bg-red-50 border-red-200 text-red-800';
    resultDiv.innerHTML = `<h4 class="font-medium">💥 Errore di rete</h4><p class="text-sm mt-1">${error.message}</p>`;
    
  } finally {
    button.innerHTML = originalText;
    button.disabled = false;
  }
}

</script>
@endsection
