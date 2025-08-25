<div class="flex justify-between items-center mb-6">
  <div>
    <h1 class="text-3xl font-bold text-gray-900">⚙️ Configurazione Widget {{ $tenant->name }}</h1>
    <p class="text-gray-600 mt-1">Impostazioni e integrazione del chatbot per questo tenant</p>
  </div>
  <div class="flex gap-3">
    <a href="{{ route('admin.widget-config.index') }}" class="btn btn-secondary">← Indietro</a>
    <a href="{{ route('admin.widget-config.edit', $tenant) }}" class="btn btn-primary">✏️ Modifica</a>
    <a href="{{ route('admin.widget-config.preview', $tenant) }}" class="btn btn-secondary" target="_blank">🔍 Preview</a>
  </div>
</div>
