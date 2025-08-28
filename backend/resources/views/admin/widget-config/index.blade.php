@extends('admin.layout')

@section('title', 'Configurazioni Widget')

@section('content')
<div class="container mx-auto px-4 py-6">
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold text-gray-900">🤖 Configurazioni Widget</h1>
    <div class="flex gap-3">
      <a href="{{ route('admin.tenants.index') }}" class="btn btn-secondary">
        👥 Gestisci Tenant
      </a>
    </div>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-lg shadow p-6 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
      <div class="flex-1 min-w-0">
        <label class="block text-sm font-medium text-gray-700 mb-1">Tenant</label>
        <select name="tenant_id" class="w-full border rounded-lg px-3 py-2">
          <option value="">Tutti i tenant</option>
          @foreach($tenants as $tenant)
            <option value="{{ $tenant->id }}" @selected(request('tenant_id') == $tenant->id)>
              {{ $tenant->name }} ({{ $tenant->slug }})
            </option>
          @endforeach
        </select>
      </div>
      
      <div class="w-48">
        <label class="block text-sm font-medium text-gray-700 mb-1">Stato</label>
        <select name="enabled" class="w-full border rounded-lg px-3 py-2">
          <option value="">Tutti</option>
          <option value="1" @selected(request('enabled') === '1')>Abilitati</option>
          <option value="0" @selected(request('enabled') === '0')>Disabilitati</option>
        </select>
      </div>
      
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary">🔍 Filtra</button>
        <a href="{{ route('admin.widget-config.index') }}" class="btn btn-secondary">🔄 Reset</a>
      </div>
    </form>
  </div>

  <!-- Widget Configs Table -->
  <div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Tenant
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Widget
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Tema
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Stato
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Ultimo Aggiornamento
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Azioni
            </th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          @forelse($configs as $config)
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div class="flex-shrink-0 h-10 w-10">
                    <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-medium">
                      {{ substr($config->tenant->name, 0, 2) }}
                    </div>
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900">
                      {{ $config->tenant->name }}
                    </div>
                    <div class="text-sm text-gray-500">
                      {{ $config->tenant->slug }}
                    </div>
                  </div>
                </div>
              </td>
              
              <td class="px-6 py-4">
                <div class="text-sm font-medium text-gray-900">
                  {{ $config->widget_name }}
                </div>
                <div class="text-sm text-gray-500">
                  {{ $config->position }} • {{ $config->auto_open ? 'Auto-open' : 'Manual' }}
                </div>
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                  @if($config->theme === 'default') bg-blue-100 text-blue-800
                  @elseif($config->theme === 'corporate') bg-gray-100 text-gray-800  
                  @elseif($config->theme === 'friendly') bg-green-100 text-green-800
                  @elseif($config->theme === 'high-contrast') bg-purple-100 text-purple-800
                  @else bg-orange-100 text-orange-800 @endif">
                  {{ ucfirst($config->theme) }}
                </span>
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap">
                @if($config->enabled)
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    ✅ Abilitato
                  </span>
                @else
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    ❌ Disabilitato
                  </span>
                @endif
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                <div>{{ $config->last_updated_at?->format('d/m/Y H:i') ?? $config->updated_at->format('d/m/Y H:i') }}</div>
                @if($config->updatedBy)
                  <div class="text-xs">da {{ $config->updatedBy->name }}</div>
                @endif
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <div class="flex items-center gap-2">
                  <a href="{{ route('admin.widget-config.show', $config->tenant) }}" 
                     class="text-blue-600 hover:text-blue-900" title="Visualizza">
                    👁️
                  </a>
                  <a href="{{ route('admin.widget-config.edit', $config->tenant) }}" 
                     class="text-indigo-600 hover:text-indigo-900" title="Modifica">
                    ✏️
                  </a>
                  <a href="{{ route('widget.preview', $config->tenant) }}" 
                     class="text-green-600 hover:text-green-900" target="_blank" title="Preview">
                    🔍
                  </a>
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="px-6 py-12 text-center">
                <div class="text-gray-500">
                  <div class="text-6xl mb-4">🤖</div>
                  <h3 class="text-lg font-medium mb-2">Nessuna configurazione widget trovata</h3>
                  <p class="mb-4">Le configurazioni widget verranno create automaticamente quando i tenant accederanno alla sezione widget.</p>
                  <a href="{{ route('admin.tenants.index') }}" class="btn btn-primary">
                    👥 Gestisci Tenant
                  </a>
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    
    @if($configs->hasPages())
      <div class="px-6 py-4 border-t border-gray-200">
        {{ $configs->links() }}
      </div>
    @endif
  </div>

  <!-- Quick Stats -->
  <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mt-6">
    <div class="bg-white rounded-lg shadow p-6">
      <div class="flex items-center">
        <div class="flex-shrink-0">
          <div class="text-2xl">📊</div>
        </div>
        <div class="ml-4">
          <div class="text-sm font-medium text-gray-500">Totale Configurazioni</div>
          <div class="text-2xl font-bold text-gray-900">{{ $configs->total() }}</div>
        </div>
      </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
      <div class="flex items-center">
        <div class="flex-shrink-0">
          <div class="text-2xl">✅</div>
        </div>
        <div class="ml-4">
          <div class="text-sm font-medium text-gray-500">Widget Abilitati</div>
          <div class="text-2xl font-bold text-green-600">
            {{ $configs->where('enabled', true)->count() }}
          </div>
        </div>
      </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
      <div class="flex items-center">
        <div class="flex-shrink-0">
          <div class="text-2xl">🎨</div>
        </div>
        <div class="ml-4">
          <div class="text-sm font-medium text-gray-500">Temi Personalizzati</div>
          <div class="text-2xl font-bold text-purple-600">
            {{ $configs->where('theme', 'custom')->count() }}
          </div>
        </div>
      </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
      <div class="flex items-center">
        <div class="flex-shrink-0">
          <div class="text-2xl">🔄</div>
        </div>
        <div class="ml-4">
          <div class="text-sm font-medium text-gray-500">Auto-open Attivi</div>
          <div class="text-2xl font-bold text-blue-600">
            {{ $configs->where('auto_open', true)->count() }}
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .btn {
    @apply inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition;
  }
  
  .btn-primary {
    @apply text-white bg-blue-600 hover:bg-blue-700 focus:ring-blue-500;
  }
  
  .btn-secondary {
    @apply text-gray-700 bg-white border-gray-300 hover:bg-gray-50 focus:ring-blue-500;
  }
</style>
@endsection
