@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Documenti – {{ $tenant->name }}</h1>
<div class="bg-white border rounded p-4 mb-6">
  <div x-data="uploader()" x-init="init()">
    <div x-ref="dropzone" class="border-2 border-dashed rounded p-6 text-gray-600 flex flex-col items-center justify-center">
      <p class="mb-2">Trascina qui i file o</p>
      <div class="flex items-center gap-2">
        <label class="px-3 py-2 bg-gray-100 rounded cursor-pointer">
          <input type="file" x-ref="fileInput" class="hidden" multiple accept=".pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx" @change="onFileChange">
          Scegli file
        </label>
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
</div>
<script>
  function uploader(){
    return {
      items:[], errors:[], uploading:false, concurrency:3, inflight:0, queueIndex:0,
      init(){
        const dz=this.$refs.dropzone;
        dz.addEventListener('dragover',e=>{e.preventDefault(); dz.classList.add('bg-indigo-50');});
        dz.addEventListener('dragleave',()=>dz.classList.remove('bg-indigo-50'));
        dz.addEventListener('drop',e=>{e.preventDefault(); dz.classList.remove('bg-indigo-50'); this.handle(Array.from(e.dataTransfer.files));});
      },
      onFileChange(e){ this.handle(Array.from(e.target.files)); },
      handle(list){
        list.forEach(f=>{
          this.items.push({ id: crypto.randomUUID(), file:f, name:f.name, progress:0, status:'queued', error:null });
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
          const form=new FormData(); form.append('files[]', it.file);
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
  <table class="w-full text-sm min-w-[900px]">
    <thead>
      <tr class="bg-gray-100 text-left">
        <th class="p-2">ID</th>
        <th class="p-2">Titolo</th>
        <th class="p-2">Stato</th>
        <th class="p-2">Progress</th>
        <th class="p-2">Errore</th>
        <th class="p-2">Sorgente</th>
        <th class="p-2">Path</th>
        <th class="p-2">Azioni</th>
      </tr>
    </thead>
    <tbody>
      @foreach($docs as $d)
      <tr class="border-t align-top">
        <td class="p-2">{{ $d->id }}</td>
        <td class="p-2">{{ $d->title }}</td>
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
        <td class="p-2">{{ $d->path }}</td>
        <td class="p-2 flex gap-2">
          @if($d->ingestion_status === 'failed')
          <form class="inline" method="post" action="{{ route('admin.documents.retry', [$tenant, $d]) }}">
            @csrf
            <button class="px-2 py-1 text-xs bg-amber-500 text-white rounded">Riprova</button>
          </form>
          @endif
          <form class="inline" method="post" action="{{ route('admin.documents.destroy', [$tenant, $d]) }}" onsubmit="return confirm('Eliminare definitivamente?')">
            @csrf @method('delete')
            <button class="px-2 py-1 text-xs bg-rose-600 text-white rounded">Elimina</button>
          </form>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
<div class="mt-4">{{ $docs->links() }}</div>
@endsection
