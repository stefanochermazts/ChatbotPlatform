@extends('admin.layout')

@section('title', "Analytics Widget - {$tenant->name}")

@section('content')
<div class="w-full py-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-2">
        <a href="{{ route('admin.widget-analytics.index') }}" class="hover:text-gray-700">📊 Analytics</a>
        <span>/</span>
        <span class="text-gray-900 font-medium">{{ $tenant->name }}</span>
      </nav>
      <h1 class="text-3xl font-bold text-gray-900">
        📈 Analytics Dettagliate - {{ $tenant->name }}
      </h1>
      <p class="text-gray-600 mt-1">
        Periodo: {{ $startDate->format('d/m/Y') }} - {{ $endDate->format('d/m/Y') }}
        ({{ $startDate->diffInDays($endDate) }} giorni)
      </p>
    </div>
    
    <div class="flex gap-3">
      <a href="{{ route('admin.widget-config.show', $tenant) }}" class="btn btn-secondary">
        ⚙️ Configurazione
      </a>
      <a href="{{ route('widget.preview', $tenant) }}" class="btn btn-primary" target="_blank">
        🔍 Anteprima Widget
      </a>
    </div>
  </div>

  <!-- Date Filter -->
  <div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
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
        <a href="{{ route('admin.widget-analytics.show', $tenant) }}" class="btn btn-secondary">🔄 Reset</a>
      </div>
    </form>
  </div>

  @if(!empty($analytics))
    <!-- Key Performance Indicators -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-6 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-blue-100">Eventi Totali</p>
            <p class="text-3xl font-bold">{{ number_format($analytics['total_events']) }}</p>
          </div>
          <div class="text-4xl opacity-80">📊</div>
        </div>
        <div class="mt-4 text-blue-100 text-sm">
          {{ round(($analytics['total_events'] / max(1, $startDate->diffInDays($endDate))), 1) }} eventi/giorno
        </div>
      </div>
      
      <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg p-6 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-green-100">Sessioni Uniche</p>
            <p class="text-3xl font-bold">{{ number_format($analytics['total_sessions']) }}</p>
          </div>
          <div class="text-4xl opacity-80">👥</div>
        </div>
        <div class="mt-4 text-green-100 text-sm">
          {{ round(($analytics['total_sessions'] / max(1, $startDate->diffInDays($endDate))), 1) }} sessioni/giorno
        </div>
      </div>
      
      <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg p-6 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-purple-100">Messaggi Inviati</p>
            <p class="text-3xl font-bold">{{ number_format($analytics['total_messages']) }}</p>
          </div>
          <div class="text-4xl opacity-80">💬</div>
        </div>
        <div class="mt-4 text-purple-100 text-sm">
          {{ $analytics['total_sessions'] > 0 ? round(($analytics['total_messages'] / $analytics['total_sessions']), 1) : 0 }} msg/sessione
        </div>
      </div>
      
      <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg p-6 text-white">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-orange-100">Tempo Risposta</p>
            <p class="text-3xl font-bold">
              @if($analytics['avg_response_time'])
                {{ number_format($analytics['avg_response_time']) }}ms
              @else
                N/A
              @endif
            </p>
          </div>
          <div class="text-4xl opacity-80">⚡</div>
        </div>
        <div class="mt-4 text-orange-100 text-sm">
          {{ $analytics['total_responses'] }} risposte generate
        </div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
      <!-- Daily Activity Chart -->
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold mb-4">📈 Attività Giornaliera</h3>
        
        @if(!empty($dailyStats))
          <div class="space-y-3">
            @foreach($dailyStats as $day => $stats)
              @php
                $maxEvents = max(array_column($dailyStats, 'events'));
                $barWidth = $maxEvents > 0 ? ($stats['events'] / $maxEvents) * 100 : 0;
              @endphp
              
              <div class="flex items-center justify-between">
                <div class="w-20 text-sm text-gray-600">
                  {{ \Carbon\Carbon::parse($day)->format('d/m') }}
                </div>
                <div class="flex-1 mx-3">
                  <div class="bg-gray-200 rounded-full h-6 relative">
                    <div class="bg-blue-500 h-6 rounded-full" style="width: {{ $barWidth }}%"></div>
                    <div class="absolute inset-0 flex items-center justify-center text-xs font-medium text-white">
                      {{ $stats['events'] }}
                    </div>
                  </div>
                </div>
                <div class="w-16 text-sm text-gray-500 text-right">
                  {{ $stats['sessions'] }} sess.
                </div>
              </div>
            @endforeach
          </div>
        @else
          <div class="text-center py-8 text-gray-500">
            <div class="text-4xl mb-2">📊</div>
            <p>Nessun dato disponibile per il periodo selezionato</p>
          </div>
        @endif
      </div>
      
      <!-- Event Types Distribution -->
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold mb-4">🎯 Distribuzione Eventi</h3>
        
        @if(!empty($eventTypes))
          <div class="space-y-4">
            @php
              $totalEvents = array_sum(array_column($eventTypes, 'count'));
            @endphp
            
            @foreach($eventTypes as $eventType)
              @php
                $percentage = $totalEvents > 0 ? round(($eventType['count'] / $totalEvents) * 100, 1) : 0;
                $icon = match($eventType['event_type']) {
                  'chatbot_opened' => '🔓',
                  'chatbot_closed' => '🔒',
                  'message_sent' => '💬',
                  'message_error' => '❌',
                  'widget_loaded' => '🚀',
                  default => '📊'
                };
                $label = match($eventType['event_type']) {
                  'chatbot_opened' => 'Chatbot Aperti',
                  'chatbot_closed' => 'Chatbot Chiusi',
                  'message_sent' => 'Messaggi Inviati',
                  'message_error' => 'Errori Messaggi',
                  'widget_loaded' => 'Widget Caricati',
                  default => ucfirst(str_replace('_', ' ', $eventType['event_type']))
                };
              @endphp
              
              <div class="flex items-center justify-between">
                <div class="flex items-center">
                  <span class="text-lg mr-2">{{ $icon }}</span>
                  <span class="text-sm font-medium">{{ $label }}</span>
                </div>
                <div class="flex items-center gap-3">
                  <div class="w-32 bg-gray-200 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width: {{ $percentage }}%"></div>
                  </div>
                  <div class="text-sm text-gray-600 w-12 text-right">
                    {{ $eventType['count'] }}
                  </div>
                  <div class="text-xs text-gray-500 w-10 text-right">
                    {{ $percentage }}%
                  </div>
                </div>
              </div>
            @endforeach
          </div>
        @else
          <div class="text-center py-8 text-gray-500">
            <div class="text-4xl mb-2">🎯</div>
            <p>Nessun evento registrato</p>
          </div>
        @endif
      </div>
    </div>

    <!-- Performance Metrics -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold mb-4">⚡ Performance</h3>
        
        <div class="space-y-4">
          <div class="flex justify-between items-center">
            <span class="text-gray-600">Tempo Risposta Medio</span>
            <div class="text-right">
              <div class="font-semibold">
                @if($analytics['avg_response_time'])
                  {{ number_format($analytics['avg_response_time']) }}ms
                @else
                  N/A
                @endif
              </div>
              @if($analytics['avg_response_time'])
                <div class="text-xs text-gray-500">
                  @if($analytics['avg_response_time'] < 1000)
                    ✅ Ottimo
                  @elseif($analytics['avg_response_time'] < 2500)
                    ⚠️ Accettabile
                  @else
                    ❌ Lento
                  @endif
                </div>
              @endif
            </div>
          </div>
          
          <div class="flex justify-between items-center">
            <span class="text-gray-600">Token Utilizzati</span>
            <div class="text-right">
              <div class="font-semibold">{{ number_format($analytics['total_tokens_used'] ?? 0) }}</div>
              <div class="text-xs text-gray-500">
                {{ $analytics['total_messages'] > 0 ? round(($analytics['total_tokens_used'] ?? 0) / $analytics['total_messages']) : 0 }} avg/msg
              </div>
            </div>
          </div>
          
          <div class="flex justify-between items-center">
            <span class="text-gray-600">Citazioni Fornite</span>
            <div class="text-right">
              <div class="font-semibold">{{ number_format($analytics['total_citations'] ?? 0) }}</div>
              <div class="text-xs text-gray-500">
                {{ $analytics['total_responses'] > 0 ? round((($analytics['total_citations'] ?? 0) / $analytics['total_responses']) * 100, 1) : 0 }}% risposte
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold mb-4">📊 Engagement</h3>
        
        <div class="space-y-4">
          @php
            $conversionRate = $analytics['total_sessions'] > 0 
              ? round(($analytics['total_messages'] / $analytics['total_sessions']) * 100, 1)
              : 0;
            $responseSuccessRate = $analytics['total_messages'] > 0 
              ? round(($analytics['total_responses'] / $analytics['total_messages']) * 100, 1)
              : 0;
          @endphp
          
          <div class="flex justify-between items-center">
            <span class="text-gray-600">Tasso Conversione</span>
            <div class="text-right">
              <div class="font-semibold">{{ $conversionRate }}%</div>
              <div class="text-xs text-gray-500">
                @if($conversionRate > 50)
                  ✅ Ottimo
                @elseif($conversionRate > 20)
                  ⚠️ Buono
                @else
                  ❌ Basso
                @endif
              </div>
            </div>
          </div>
          
          <div class="flex justify-between items-center">
            <span class="text-gray-600">Successo Risposte</span>
            <div class="text-right">
              <div class="font-semibold">{{ $responseSuccessRate }}%</div>
              <div class="text-xs text-gray-500">
                @if($responseSuccessRate > 95)
                  ✅ Eccellente
                @elseif($responseSuccessRate > 85)
                  ⚠️ Buono
                @else
                  ❌ Problemi
                @endif
              </div>
            </div>
          </div>
          
          <div class="flex justify-between items-center">
            <span class="text-gray-600">Msg per Sessione</span>
            <div class="text-right">
              <div class="font-semibold">
                {{ $analytics['total_sessions'] > 0 ? round($analytics['total_messages'] / $analytics['total_sessions'], 1) : 0 }}
              </div>
              <div class="text-xs text-gray-500">Coinvolgimento utenti</div>
            </div>
          </div>
        </div>
      </div>
      
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold mb-4">🔄 Azioni Rapide</h3>
        
        <div class="space-y-3">
          <a href="{{ route('admin.widget-analytics.export', $tenant) }}?start_date={{ $startDate->format('Y-m-d') }}&end_date={{ $endDate->format('Y-m-d') }}" 
             class="block w-full text-center bg-blue-50 text-blue-700 py-2 px-4 rounded-lg hover:bg-blue-100 transition">
            📥 Esporta Dati CSV
          </a>
          
          <a href="{{ route('admin.widget-config.edit', $tenant) }}" 
             class="block w-full text-center bg-green-50 text-green-700 py-2 px-4 rounded-lg hover:bg-green-100 transition">
            ⚙️ Modifica Configurazione
          </a>
          
          <a href="{{ route('admin.rag.index') }}?tenant_id={{ $tenant->id }}" 
             class="block w-full text-center bg-purple-50 text-purple-700 py-2 px-4 rounded-lg hover:bg-purple-100 transition">
            🔬 Test RAG
          </a>
          
          <a href="{{ route('widget.preview', $tenant) }}" 
             class="block w-full text-center bg-orange-50 text-orange-700 py-2 px-4 rounded-lg hover:bg-orange-100 transition" target="_blank">
             🔍 Anteprima Live
          </a>
        </div>
      </div>
    </div>

    <!-- Recent Events Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold">📋 Eventi Recenti</h3>
        <p class="text-gray-600 text-sm mt-1">Ultimi 50 eventi registrati nel periodo selezionato</p>
      </div>
      
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Timestamp
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Evento
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Sessione
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Dettagli
              </th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            @forelse($recentEvents as $event)
              @php
                $icon = match($event->event_type) {
                  'chatbot_opened' => '🔓',
                  'chatbot_closed' => '🔒',
                  'message_sent' => '💬',
                  'message_error' => '❌',
                  'widget_loaded' => '🚀',
                  default => '📊'
                };
                
                $eventLabel = match($event->event_type) {
                  'chatbot_opened' => 'Chatbot Aperto',
                  'chatbot_closed' => 'Chatbot Chiuso',
                  'message_sent' => 'Messaggio Inviato',
                  'message_error' => 'Errore Messaggio',
                  'widget_loaded' => 'Widget Caricato',
                  default => ucfirst(str_replace('_', ' ', $event->event_type))
                };
              @endphp
              
              <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  {{ $event->created_at->format('d/m/Y H:i:s') }}
                </td>
                
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center">
                    <span class="text-lg mr-2">{{ $icon }}</span>
                    <span class="text-sm font-medium text-gray-900">{{ $eventLabel }}</span>
                  </div>
                </td>
                
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  <code class="text-xs bg-gray-100 px-2 py-1 rounded">
                    {{ Str::limit($event->session_id, 12) }}
                  </code>
                </td>
                
                <td class="px-6 py-4 text-sm text-gray-500">
                  @if($event->event_data)
                    @php
                      $data = is_array($event->event_data) ? $event->event_data : json_decode($event->event_data, true);
                    @endphp
                    
                    @if(isset($data['query']) && !empty($data['query']))
                      <div class="max-w-xs truncate" title="{{ $data['query'] }}">
                        <strong>Query:</strong> {{ Str::limit($data['query'], 50) }}
                      </div>
                    @endif
                    
                    @if(isset($data['response_time']) && !empty($data['response_time']))
                      <div class="text-xs text-gray-400">
                        ⚡ {{ number_format($data['response_time']) }}ms
                      </div>
                    @endif
                    
                    @if(isset($data['citations']) && is_numeric($data['citations']))
                      <div class="text-xs text-gray-400">
                        📎 {{ $data['citations'] }} citazioni
                      </div>
                    @endif
                    
                    @if(isset($data['error']) && !empty($data['error']))
                      <div class="text-xs text-red-600 max-w-xs truncate" title="{{ $data['error'] }}">
                        ❌ {{ Str::limit($data['error'], 50) }}
                      </div>
                    @endif
                  @else
                    <span class="text-gray-400">-</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="4" class="px-6 py-12 text-center">
                  <div class="text-gray-500">
                    <div class="text-4xl mb-2">📊</div>
                    <h3 class="text-lg font-medium mb-2">Nessun evento trovato</h3>
                    <p>Non ci sono eventi per il periodo selezionato.</p>
                  </div>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  @else
    <!-- No Data State -->
    <div class="bg-white rounded-lg shadow p-12 text-center">
      <div class="text-gray-500">
        <div class="text-6xl mb-4">📊</div>
        <h2 class="text-2xl font-medium mb-4">Nessun dato disponibile</h2>
        <p class="mb-6">Non ci sono analytics disponibili per il periodo selezionato.</p>
        
        <div class="flex justify-center gap-4">
          <a href="{{ route('admin.widget-config.edit', $tenant) }}" class="btn btn-primary">
            ⚙️ Configura Widget
          </a>
          <a href="{{ route('widget.preview', $tenant) }}" class="btn btn-secondary" target="_blank">
            🔍 Test Widget
          </a>
        </div>
      </div>
    </div>
  @endif
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
