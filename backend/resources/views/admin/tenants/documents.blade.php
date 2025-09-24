<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Documenti – {{ $tenant->name }}</title>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.4/dist/tailwind.min.css">
  <style>
    .dropzone{border:2px dashed #cbd5e1;border-radius:.75rem;padding:2rem;background:#f8fafc}
    .dropzone.dragover{background:#eef2ff;border-color:#818cf8}
  </style>
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <script>
    function uploader() {
      return {
        uploading: false,
        progress: 0,
        files: [],
        errors: [],
        init() {
          const dz = this.$refs.dropzone;
          dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
          dz.addEventListener('dragleave', e => { dz.classList.remove('dragover'); });
          dz.addEventListener('drop', e => {
            e.preventDefault(); dz.classList.remove('dragover');
            this.handleFiles(e.dataTransfer.files);
          });
        },
        chooseFiles() { this.$refs.fileInput.click(); },
        onFileChange(e) { this.handleFiles(e.target.files); },
        handleFiles(list) {
          const arr = Array.from(list);
          this.files.push(...arr);
        },
        async upload() {
          if (this.files.length === 0) return;
          this.uploading = true; this.progress = 0; this.errors = [];
          const form = new FormData();
          this.files.forEach(f => form.append('files[]', f));
          const csrf = document.querySelector('meta[name=csrf-token]').getAttribute('content');
          const res = await fetch('{{ route('admin.tenants.documents.upload', $tenant) }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf },
            body: form,
          });
          if (!res.ok) {
            const msg = await res.text();
            this.errors.push(msg);
          } else {
            const data = await res.json();
            if (data.errors && data.errors.length) {
              data.errors.forEach(e => this.errors.push(`${e.name || e.index}: ${e.error}`));
            }
            this.files = [];
            window.location.reload();
          }
          this.uploading = false;
        }
      };
    }
  </script>
</head>
<body class="bg-slate-50">
  <div class="max-w-6xl mx-auto p-6">
    <h1 class="text-2xl font-semibold mb-4">Documenti – {{ $tenant->name }}</h1>

    <div x-data="uploader()" x-init="init()" class="mb-8">
      <div x-ref="dropzone" class="dropzone flex flex-col items-center justify-center text-slate-600">
        <p class="mb-2">Trascina qui i file o</p>
        <button @click="chooseFiles" class="px-4 py-2 bg-indigo-600 text-white rounded">Scegli file</button>
        <input type="file" x-ref="fileInput" class="hidden" multiple @change="onFileChange">
      </div>
      <div class="mt-3 text-sm" x-show="files.length">
        <p class="font-medium">File selezionati ({{ files.length }})</p>
        <ul class="list-disc ml-6 max-h-40 overflow-auto">
          <template x-for="f in files" :key="f.name">
            <li x-text="f.name"></li>
          </template>
        </ul>
        <div class="mt-3 flex items-center gap-3">
          <button @click="upload" :disabled="uploading" class="px-4 py-2 bg-emerald-600 text-white rounded disabled:opacity-50">Carica</button>
          <span x-show="uploading">Caricamento in corso...</span>
        </div>
        <div class="mt-3 text-red-600" x-show="errors.length">
          <p class="font-medium">Errori:</p>
          <ul class="list-disc ml-6">
            <template x-for="e in errors" :key="e">
              <li x-text="e"></li>
            </template>
          </ul>
        </div>
      </div>
    </div>

    <div class="bg-white shadow rounded">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100">
          <tr>
            <th class="text-left p-3">Titolo</th>
            <th class="text-left p-3">Fonte</th>
            <th class="text-left p-3">Stato</th>
            <th class="text-left p-3">Azione</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($documents as $doc)
            <tr class="border-t">
              <td class="p-3">{{ $doc->title }}</td>
              <td class="p-3">{{ $doc->source }}</td>
              <td class="p-3">{{ $doc->ingestion_status }}</td>
              <td class="p-3">
                <a class="text-indigo-600 hover:underline mr-3" href="{{ url('storage/'.$doc->path) }}" target="_blank">Apri</a>
                @if(!empty($doc->extracted_path))
                  <a class="text-emerald-600 hover:underline" href="{{ url('storage/'.$doc->extracted_path) }}" target="_blank" title="Testo estratto (Markdown)">Testo estratto</a>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="p-3">{{ $documents->links() }}</div>
    </div>
  </div>
</body>
</html>


