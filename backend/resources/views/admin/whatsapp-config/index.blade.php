@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">Configurazioni WhatsApp</h1>
    <p class="text-gray-600">Gestisci le configurazioni WhatsApp per tutti i tenant</p>
  </div>
</div>

<!-- Statistiche Generali -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="text-2xl font-bold text-blue-600">{{ $tenants->count() }}</div>
    <div class="text-sm text-gray-600">Tenant Totali</div>
  </div>
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="text-2xl font-bold text-green-600">
      {{ $tenants->where('whatsapp_status', 'active')->count() }}
    </div>
    <div class="text-sm text-gray-600">WhatsApp Attivi</div>
  </div>
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="text-2xl font-bold text-yellow-600">
      {{ $tenants->where('whatsapp_status', 'inactive')->count() }}
    </div>
    <div class="text-sm text-gray-600">Non Configurati</div>
  </div>
  <div class="bg-white p-4 rounded-lg shadow border">
    <div class="text-2xl font-bold text-purple-600">
      {{ $tenants->sum('messages_count') }}
    </div>
    <div class="text-sm text-gray-600">Messaggi Totali</div>
  </div>
</div>

<!-- Lista Tenant -->
<div class="bg-white rounded-lg shadow overflow-hidden">
  <table class="min-w-full divide-y divide-gray-200">
    <thead class="bg-gray-50">
      <tr>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Tenant
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Numero WhatsApp
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Stato
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Messaggi
        </th>
        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
          Azioni
        </th>
      </tr>
    </thead>
    <tbody class="bg-white divide-y divide-gray-200">
      @forelse($tenants as $tenant)
        <tr class="hover:bg-gray-50">
          <td class="px-6 py-4 whitespace-nowrap">
            <div>
              <div class="text-sm font-medium text-gray-900">{{ $tenant->name }}</div>
              <div class="text-sm text-gray-500">{{ $tenant->slug }}</div>
            </div>
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            @if($tenant->whatsapp_number)
              <span class="text-sm text-gray-900">{{ $tenant->whatsapp_number }}</span>
            @else
              <span class="text-sm text-gray-400 italic">Non configurato</span>
            @endif
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
              {{ $tenant->whatsapp_status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
              <div class="w-2 h-2 rounded-full mr-1 
                {{ $tenant->whatsapp_status === 'active' ? 'bg-green-500' : 'bg-gray-400' }}"></div>
              {{ $tenant->whatsapp_status === 'active' ? 'Attivo' : 'Inattivo' }}
            </span>
          </td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            {{ $tenant->messages_count }}
          </td>
          <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
            <div class="flex justify-end space-x-2">
              <a href="{{ route('admin.whatsapp-config.show', $tenant) }}" 
                 class="text-blue-600 hover:text-blue-900">
                {{ $tenant->whatsapp_status === 'active' ? 'Gestisci' : 'Configura' }}
              </a>
              
              @if($tenant->whatsapp_status === 'active')
                <span class="text-gray-300">|</span>
                <button onclick="testConfig({{ $tenant->id }})" 
                        class="text-green-600 hover:text-green-900">
                  Test
                </button>
              @endif
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="5" class="px-6 py-12 text-center text-gray-500">
            Nessun tenant trovato.
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

<!-- Webhook URLs Info -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
  <h4 class="text-lg font-medium text-blue-900 mb-2">🔗 URL Webhook Globali</h4>
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
      Usa questi URL nel dashboard Vonage per tutti i numeri WhatsApp Business. 
      Il sistema identifica automaticamente il tenant corretto dal numero di destinazione.
    </p>
  </div>
</div>

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
function testConfig(tenantId) {
  fetch(`/admin/tenants/${tenantId}/whatsapp-config/test`, {
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
}

function closeTestModal() {
  document.getElementById('test-result').classList.add('hidden');
}
</script>
@endsection
