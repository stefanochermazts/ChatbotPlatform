@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Configurazione WhatsApp</h1>
    <p class="text-gray-600">{{ $tenant->name }}</p>
  </div>
  
  <div class="flex gap-3">
    <button id="test-config" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
      🧪 Test Configurazione
    </button>
    <a href="{{ route('admin.whatsapp-config.index') }}" class="text-gray-600 hover:text-gray-800">
      ← Torna alle Configurazioni WhatsApp
    </a>
  </div>
</div>

<!-- Status e Info -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="flex items-center">
      <div class="w-3 h-3 rounded-full {{ $config['is_active'] ? 'bg-green-500' : 'bg-red-500' }} mr-3"></div>
      <div>
        <div class="text-lg font-semibold">{{ $config['is_active'] ? 'Attivo' : 'Inattivo' }}</div>
        <div class="text-sm text-gray-600">Stato WhatsApp</div>
      </div>
    </div>
  </div>
  
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="text-lg font-semibold">{{ $config['phone_number'] ?: 'Non configurato' }}</div>
    <div class="text-sm text-gray-600">Numero WhatsApp</div>
  </div>
  
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="text-lg font-semibold">{{ $tenant->vonageMessages()->count() }}</div>
    <div class="text-sm text-gray-600">Messaggi Totali</div>
  </div>
</div>

<!-- Form Configurazione -->
<form method="POST" action="{{ route('admin.whatsapp-config.update', $tenant) }}" class="space-y-6">
  @csrf
  @method('PUT')
  
  <!-- Configurazione Base -->
  <div class="bg-white rounded-lg shadow p-6">
    <h3 class="text-lg font-medium text-gray-900 mb-4">Configurazione Base</h3>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Numero WhatsApp Business
        </label>
        <input type="text" name="phone_number" value="{{ old('phone_number', $config['phone_number']) }}" 
               placeholder="+393331234567"
               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                      focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <p class="mt-1 text-sm text-gray-500">
          Formato: +39333123456 (numero WhatsApp Business verificato su Vonage)
        </p>
        @error('phone_number')
          <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
      </div>
      
      <div>
        <label class="flex items-center">
          <input type="hidden" name="is_active" value="0">
          <input type="checkbox" name="is_active" value="1" 
                 {{ old('is_active', $config['is_active']) ? 'checked' : '' }}
                 class="rounded border-gray-300 text-blue-600 shadow-sm 
                        focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
          <span class="ml-2 text-sm text-gray-700">Attiva WhatsApp per questo tenant</span>
        </label>
      </div>
    </div>
    
    <div class="mt-4">
      <label class="block text-sm font-medium text-gray-700 mb-2">
        Messaggio di Benvenuto
      </label>
      <textarea name="welcome_message" rows="3" 
                placeholder="Ciao! Sono l'assistente virtuale di {{ $tenant->name }}. Come posso aiutarti?"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                       focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('welcome_message', $config['welcome_message']) }}</textarea>
      <p class="mt-1 text-sm text-gray-500">
        Messaggio inviato quando un utente inizia una conversazione (facoltativo)
      </p>
    </div>
  </div>

  <!-- Orari di Lavoro -->
  <div class="bg-white rounded-lg shadow p-6" x-data="businessHours()">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-medium text-gray-900">Orari di Lavoro</h3>
      <label class="flex items-center">
        <input type="hidden" name="business_hours[enabled]" value="0">
        <input type="checkbox" name="business_hours[enabled]" value="1" 
               x-model="enabled"
               {{ old('business_hours.enabled', $config['business_hours']['enabled']) ? 'checked' : '' }}
               class="rounded border-gray-300 text-blue-600">
        <span class="ml-2 text-sm text-gray-700">Abilita orari di lavoro</span>
      </label>
    </div>
    
    <div x-show="enabled" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Fuso Orario</label>
        <select name="business_hours[timezone]" class="w-full px-3 py-2 border border-gray-300 rounded-md">
          <option value="Europe/Rome" {{ old('business_hours.timezone', $config['business_hours']['timezone']) == 'Europe/Rome' ? 'selected' : '' }}>Europe/Rome</option>
          <option value="Europe/London" {{ old('business_hours.timezone', $config['business_hours']['timezone']) == 'Europe/London' ? 'selected' : '' }}>Europe/London</option>
          <option value="America/New_York" {{ old('business_hours.timezone', $config['business_hours']['timezone']) == 'America/New_York' ? 'selected' : '' }}>America/New_York</option>
        </select>
      </div>
      
      <!-- Giorni della settimana -->
      @php
        $days = [
          'monday' => 'Lunedì',
          'tuesday' => 'Martedì', 
          'wednesday' => 'Mercoledì',
          'thursday' => 'Giovedì',
          'friday' => 'Venerdì',
          'saturday' => 'Sabato',
          'sunday' => 'Domenica'
        ];
      @endphp
      
      @foreach($days as $day => $label)
        <div class="flex items-center space-x-4">
          <div class="w-24">
            <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
          </div>
          
          @if($day === 'sunday')
            <label class="flex items-center">
              <input type="hidden" name="business_hours[{{ $day }}][closed]" value="0">
              <input type="checkbox" name="business_hours[{{ $day }}][closed]" value="1"
                     {{ old('business_hours.'.$day.'.closed', $config['business_hours'][$day]['closed'] ?? true) ? 'checked' : '' }}
                     class="rounded border-gray-300 text-blue-600">
              <span class="ml-2 text-sm text-gray-700">Chiuso</span>
            </label>
          @endif
          
          <div class="flex items-center space-x-2">
            <input type="time" name="business_hours[{{ $day }}][start]" 
                   value="{{ old('business_hours.'.$day.'.start', $config['business_hours'][$day]['start'] ?? '09:00') }}"
                   class="px-3 py-1 border border-gray-300 rounded text-sm">
            <span class="text-gray-500">-</span>
            <input type="time" name="business_hours[{{ $day }}][end]" 
                   value="{{ old('business_hours.'.$day.'.end', $config['business_hours'][$day]['end'] ?? '18:00') }}"
                   class="px-3 py-1 border border-gray-300 rounded text-sm">
          </div>
        </div>
      @endforeach
    </div>
  </div>

  <!-- Risposta Automatica -->
  <div class="bg-white rounded-lg shadow p-6">
    <h3 class="text-lg font-medium text-gray-900 mb-4">Risposta Automatica</h3>
    
    <div class="space-y-4">
      <label class="flex items-center">
        <input type="hidden" name="auto_response[enabled]" value="0">
        <input type="checkbox" name="auto_response[enabled]" value="1" 
               {{ old('auto_response.enabled', $config['auto_response']['enabled']) ? 'checked' : '' }}
               class="rounded border-gray-300 text-blue-600">
        <span class="ml-2 text-sm text-gray-700">Abilita risposta automatica</span>
      </label>
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">
          Ritardo Risposta (secondi)
        </label>
        <input type="number" name="auto_response[response_delay]" min="0" max="60"
               value="{{ old('auto_response.response_delay', $config['auto_response']['response_delay']) }}"
               class="w-32 px-3 py-2 border border-gray-300 rounded-md">
        <p class="mt-1 text-sm text-gray-500">
          Tempo di attesa prima di inviare la risposta (0-60 secondi)
        </p>
      </div>
    </div>
  </div>

  <!-- Webhook URLs Info -->
  <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
    <h4 class="text-lg font-medium text-blue-900 mb-2">🔗 URL Webhook per Vonage</h4>
    <div class="space-y-2 text-sm">
      <div>
        <strong>Inbound URL:</strong> 
        <code class="bg-white px-2 py-1 rounded">{{ config('app.url') }}/api/v1/vonage/whatsapp/inbound</code>
      </div>
      <div>
        <strong>Status URL:</strong> 
        <code class="bg-white px-2 py-1 rounded">{{ config('app.url') }}/api/v1/vonage/whatsapp/status</code>
      </div>
      <p class="text-blue-700 mt-2">
        Configura questi URL nel dashboard Vonage per il numero WhatsApp Business associato a questo tenant.
      </p>
    </div>
  </div>

  <!-- Submit -->
  <div class="flex justify-end">
    <button type="submit" 
            class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 
                   focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
      💾 Salva Configurazione
    </button>
  </div>
</form>

<!-- Test Result Modal -->
<div id="test-result" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
  <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
    <div class="mt-3">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Risultato Test</h3>
      <div id="test-content" class="text-sm"></div>
      <div class="mt-4 flex justify-end">
        <button onclick="closeTestModal()" 
                class="bg-gray-300 text-gray-800 px-4 py-2 rounded hover:bg-gray-400">
          Chiudi
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function businessHours() {
  return {
    enabled: {{ old('business_hours.enabled', $config['business_hours']['enabled']) ? 'true' : 'false' }}
  }
}

document.getElementById('test-config').addEventListener('click', function() {
  fetch('{{ route("admin.whatsapp-config.test", $tenant) }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': '{{ csrf_token() }}'
    }
  })
  .then(response => response.json())
  .then(data => {
    const content = document.getElementById('test-content');
    if (data.success) {
      content.innerHTML = `
        <div class="text-green-600 mb-2">✅ ${data.message}</div>
        <div class="text-sm text-gray-600">
          <p><strong>Numero:</strong> ${data.details.phone_number}</p>
          <p><strong>Saldo Vonage:</strong> €${data.details.vonage_balance}</p>
          <p class="mt-2"><strong>Webhook URLs:</strong></p>
          <p class="text-xs">Inbound: ${data.details.webhook_urls.inbound}</p>
          <p class="text-xs">Status: ${data.details.webhook_urls.status}</p>
        </div>
      `;
    } else {
      content.innerHTML = `<div class="text-red-600">❌ ${data.message}</div>`;
    }
    document.getElementById('test-result').classList.remove('hidden');
  })
  .catch(error => {
    const content = document.getElementById('test-content');
    content.innerHTML = `<div class="text-red-600">❌ Errore nel test: ${error.message}</div>`;
    document.getElementById('test-result').classList.remove('hidden');
  });
});

function closeTestModal() {
  document.getElementById('test-result').classList.add('hidden');
}
</script>
@endsection
