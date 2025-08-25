@extends('admin.layout')

@section('title', 'Analytics Widget')

@section('content')
<div class="container mx-auto px-4 py-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">📊 Analytics Widget</h1>
      <p class="text-gray-600 mt-1">Analisi dettagliate dell'utilizzo del chatbot</p>
    </div>
    
    <div class="flex gap-3">
      <a href="{{ route('admin.widget-config.index') }}" class="btn btn-secondary">
        🛠️ Configurazioni
      </a>
    </div>
  </div>

  <!-- Filters -->
  <div class="bg-white rounded-lg shadow p-6 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
      <div class="flex-1 min-w-0">
        <label class="block text-sm font-medium text-gray-700 mb-1">Tenant</label>
        <select name="tenant_id" class="w-full border rounded-lg px-3 py-2" onchange="this.form.submit()">
          <option value="">Seleziona tenant per analytics dettagliate</option>
          @foreach($tenants as $tenantOption)
            <option value="{{ $tenantOption->id }}" @selected(request('tenant_id') == $tenantOption->id)>
              {{ $tenantOption->name }} ({{ $tenantOption->slug }})
            </option>
          @endforeach
        </select>
      </div>
      
      <div class="w-48">
        <label class="block text-sm font-medium text-gray-700 mb-1">Data Inizio</label>
        <input type="date" name="start_date" value="{{ request('start_date', $startDate->format('Y-m-d')) }}"
               class="w-full border rounded-lg px-3 py-2">
      </div>
      
      <div class="w-48">
        <label class="block text-sm font-medium text-gray-700 mb-1">Data Fine</label>
        <input type="date" name="end_date" value="{{ request('end_date', $endDate->format('Y-m-d')) }}"
               class="w-full border rounded-lg px-3 py-2">
      </div>
      
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary">📊 Aggiorna</button>
        <a href="{{ route('admin.widget-analytics.index') }}" class="btn btn-secondary">🔄 Reset</a>
      </div>
    </form>
  </div>

  @if($tenant && !empty($analytics))
    <!-- Selected Tenant Analytics -->
    <div class="mb-6">
      <div class="bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg p-6 text-white mb-6">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-2xl font-bold">Analytics per {{ $tenant->name }}</h2>
            <p class="opacity-90">
              Periodo: {{ $startDate->format('d/m/Y') }} - {{ $endDate->format('d/m/Y') }}
              ({{ $startDate->diffInDays($endDate) }} giorni)
            </p>
          </div>
          <div class="text-right">
            <a href="{{ route('admin.widget-analytics.show', $tenant) }}?start_date={{ $startDate->format('Y-m-d') }}&end_date={{ $endDate->format('Y-m-d') }}" 
               class="bg-white text-blue-600 px-4 py-2 rounded-lg font-medium hover:bg-gray-100 transition">
              📈 Vista Dettagliata
            </a>
          </div>
        </div>
      </div>

      <!-- Key Metrics -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="text-3xl">📊</div>
            </div>
            <div class="ml-4">
              <div class="text-sm font-medium text-gray-500">Eventi Totali</div>
              <div class="text-2xl font-bold text-gray-900">
                {{ number_format($analytics['total_events'] ?? 0) }}
              </div>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="text-3xl">👥</div>
            </div>
            <div class="ml-4">
              <div class="text-sm font-medium text-gray-500">Sessioni Uniche</div>
              <div class="text-2xl font-bold text-gray-900">
                {{ number_format($analytics['total_sessions'] ?? 0) }}
              </div>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="text-3xl">💬</div>
            </div>
            <div class="ml-4">
              <div class="text-sm font-medium text-gray-500">Messaggi Inviati</div>
              <div class="text-2xl font-bold text-gray-900">
                {{ number_format($analytics['total_messages'] ?? 0) }}
              </div>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0">
              <div class="text-3xl">🤖</div>
            </div>
            <div class="ml-4">
              <div class="text-sm font-medium text-gray-500">Risposte Generate</div>
              <div class="text-2xl font-bold text-gray-900">
                {{ number_format($analytics['total_responses'] ?? 0) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Performance Metrics -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
          <h3 class="text-lg font-semibold mb-4">⚡ Performance</h3>
          
          <div class="space-y-3">
            <div class="flex justify-between">
              <span class="text-gray-600">Tempo Risposta Medio:</span>
              <span class="font-medium">
                @if($analytics['avg_response_time'])
                  {{ number_format($analytics['avg_response_time']) }}ms
                @else
                  N/A
                @endif
              </span>
            </div>
            
            <div class="flex justify-between">
              <span class="text-gray-600">Token Utilizzati:</span>
              <span class="font-medium">{{ number_format($analytics['total_tokens_used'] ?? 0) }}</span>
            </div>
            
            <div class="flex justify-between">
              <span class="text-gray-600">Widget Aperti:</span>
              <span class="font-medium">{{ number_format($analytics['widget_opens'] ?? 0) }}</span>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <h3 class="text-lg font-semibold mb-4">📈 Engagement</h3>
          
          <div class="space-y-3">
            @php 
              $conversionRate = ($analytics['total_sessions'] ?? 0) > 0 
                ? round((($analytics['total_messages'] ?? 0) / $analytics['total_sessions']) * 100, 1)
                : 0;
              $responseRate = ($analytics['total_messages'] ?? 0) > 0 
                ? round((($analytics['total_responses'] ?? 0) / $analytics['total_messages']) * 100, 1)
                : 0;
            @endphp
            
            <div class="flex justify-between">
              <span class="text-gray-600">Tasso Conversione:</span>
              <span class="font-medium">{{ $conversionRate }}%</span>
            </div>
            
            <div class="flex justify-between">
              <span class="text-gray-600">Tasso Risposta:</span>
              <span class="font-medium">{{ $responseRate }}%</span>
            </div>
            
            <div class="flex justify-between">
              <span class="text-gray-600">Msg per Sessione:</span>
              <span class="font-medium">
                {{ ($analytics['total_sessions'] ?? 0) > 0 ? round(($analytics['total_messages'] ?? 0) / $analytics['total_sessions'], 1) : 0 }}
              </span>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <h3 class="text-lg font-semibold mb-4">🎯 Azioni Rapide</h3>
          
          <div class="space-y-3">
            <a href="{{ route('admin.widget-analytics.show', $tenant) }}" 
               class="block text-center bg-blue-50 text-blue-700 py-2 px-4 rounded-lg hover:bg-blue-100 transition">
              📊 Analytics Dettagliate
            </a>
            
            <a href="{{ route('admin.widget-config.show', $tenant) }}" 
               class="block text-center bg-green-50 text-green-700 py-2 px-4 rounded-lg hover:bg-green-100 transition">
              ⚙️ Configurazione Widget
            </a>
            
            <a href="{{ route('admin.widget-config.preview', $tenant) }}" 
               class="block text-center bg-purple-50 text-purple-700 py-2 px-4 rounded-lg hover:bg-purple-100 transition" target="_blank">
              🔍 Anteprima Widget
            </a>
          </div>
        </div>
      </div>
    </div>
  @endif

  <!-- Tenants Overview -->
  <div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200">
      <h2 class="text-xl font-semibold">📋 Panoramica Tenant</h2>
      <p class="text-gray-600 text-sm mt-1">Seleziona un tenant per visualizzare analytics dettagliate</p>
    </div>
    
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Tenant
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Widget Status
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Ultimo Utilizzo
            </th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Azioni
            </th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          @forelse($tenants as $tenantItem)
            @php
              $hasConfig = $tenantItem->widgetConfig !== null;
              $isEnabled = $hasConfig && $tenantItem->widgetConfig->enabled;
              
              // Get recent activity (last 7 days)
              $recentActivity = \App\Models\WidgetEvent::forTenant($tenantItem->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();
            @endphp
            
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div class="flex-shrink-0 h-10 w-10">
                    <div class="h-10 w-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white font-medium">
                      {{ substr($tenantItem->name, 0, 2) }}
                    </div>
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900">
                      {{ $tenantItem->name }}
                    </div>
                    <div class="text-sm text-gray-500">
                      {{ $tenantItem->slug }}
                    </div>
                  </div>
                </div>
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap">
                @if($hasConfig)
                  @if($isEnabled)
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                      ✅ Attivo
                    </span>
                  @else
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                      ❌ Disabilitato
                    </span>
                  @endif
                @else
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    ⚪ Non Configurato
                  </span>
                @endif
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                @if($recentActivity > 0)
                  <div class="text-green-600 font-medium">{{ $recentActivity }} eventi (7gg)</div>
                @else
                  <div class="text-gray-400">Nessuna attività recente</div>
                @endif
              </td>
              
              <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                <div class="flex items-center gap-2">
                  <a href="{{ route('admin.widget-analytics.index') }}?tenant_id={{ $tenantItem->id }}" 
                     class="text-blue-600 hover:text-blue-900" title="Analytics">
                    📊
                  </a>
                  
                  @if($hasConfig)
                    <a href="{{ route('admin.widget-config.show', $tenantItem) }}" 
                       class="text-green-600 hover:text-green-900" title="Configurazione">
                      ⚙️
                    </a>
                  @else
                    <a href="{{ route('admin.widget-config.edit', $tenantItem) }}" 
                       class="text-orange-600 hover:text-orange-900" title="Configura">
                      🛠️
                    </a>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-6 py-12 text-center">
                <div class="text-gray-500">
                  <div class="text-6xl mb-4">📊</div>
                  <h3 class="text-lg font-medium mb-2">Nessun tenant trovato</h3>
                  <p class="mb-4">Crea tenant per iniziare a raccogliere analytics widget.</p>
                  <a href="{{ route('admin.tenants.create') }}" class="btn btn-primary">
                    👥 Crea Tenant
                  </a>
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
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
