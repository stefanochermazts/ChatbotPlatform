<div class="bg-white rounded-lg shadow p-6">
  <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
    <div>
      <div class="text-sm text-gray-600">Tema</div>
      <div class="text-gray-900 font-medium">{{ $config->theme ?? 'default' }}</div>
    </div>
    <div>
      <div class="text-sm text-gray-600">Posizione</div>
      <div class="text-gray-900 font-medium">{{ $config->position ?? 'bottom-right' }}</div>
    </div>
    <div>
      <div class="text-sm text-gray-600">Stato</div>
      <div class="text-gray-900 font-medium">{{ $config->enabled ? 'Abilitato' : 'Disabilitato' }}</div>
    </div>
    <div>
      <div class="text-sm text-gray-600">Ultimo aggiornamento</div>
      <div class="text-gray-900 font-medium">{{ $config->last_updated_at?->format('d/m/Y H:i') ?? $config->updated_at?->format('d/m/Y H:i') }}</div>
    </div>
  </div>
</div>
