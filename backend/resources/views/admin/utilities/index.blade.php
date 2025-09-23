@extends('admin.layout')

@section('title', 'Super Admin Utilities')

@section('content')
<style>
/* CSS per utilities page */
.utility-card {
  transition: all 0.2s ease;
}
.utility-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.command-copy {
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
  font-size: 0.85rem;
  background: #f8f9fa;
  border: 1px solid #e9ecef;
  border-radius: 4px;
  padding: 0.5rem;
  cursor: pointer;
  word-break: break-all;
}
.command-copy:hover {
  background: #e9ecef;
}
.parameter-badge {
  font-size: 0.75rem;
  padding: 0.25rem 0.5rem;
  border-radius: 12px;
  font-weight: 500;
}
.param-required { background: #fee2e2; color: #dc2626; }
.param-optional { background: #e0f2fe; color: #0369a1; }
.param-flag { background: #f3e8ff; color: #7c3aed; }
</style>

<div class="w-full py-6">
  <!-- Header -->
  <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
    <div class="p-6 bg-gradient-to-r from-blue-600 to-purple-600 text-white">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-bold">⚡ Super Admin Utilities</h1>
          <p class="mt-2 text-blue-100">Strumenti avanzati per l'amministrazione della piattaforma</p>
        </div>
        <div class="text-right">
          <div class="text-sm opacity-75">Logged as</div>
          <div class="font-semibold">{{ Auth::user()->name }}</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Avviso di sicurezza -->
  <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
    <div class="flex">
      <div class="flex-shrink-0">
        <svg class="h-5 w-5 text-amber-400" viewBox="0 0 20 20" fill="currentColor">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
        </svg>
      </div>
      <div class="ml-3">
        <h3 class="text-sm font-medium text-amber-800">⚠️ Attenzione - Strumenti Avanzati</h3>
        <div class="mt-2 text-sm text-amber-700">
          <ul class="list-disc list-inside space-y-1">
            <li>Questi comandi possono influire sul funzionamento del sistema in produzione</li>
            <li>Usa sempre l'opzione <code>--dry-run</code> quando disponibile per testare prima</li>
            <li>Esegui backup prima di operazioni che modificano dati</li>
            <li>Monitora i log durante e dopo l'esecuzione dei comandi</li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Utilities Grid -->
  <div class="grid gap-6">
    @foreach($utilities as $categoryKey => $category)
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg utility-card">
      <div class="p-6">
        <!-- Category Header -->
        <div class="border-b border-gray-200 pb-4 mb-6">
          <h2 class="text-xl font-semibold text-gray-900">{{ $category['title'] }}</h2>
          <p class="mt-1 text-sm text-gray-600">{{ $category['description'] }}</p>
        </div>

        <!-- Utilities in Category -->
        <div class="space-y-6">
          @foreach($category['utilities'] as $utility)
          <div class="border border-gray-200 rounded-lg p-4 hover:border-blue-300 transition-colors">
            <div class="flex items-start justify-between mb-3">
              <h3 class="text-lg font-medium text-gray-900">{{ $utility['name'] }}</h3>
              @if(isset($utility['script']))
              <button onclick="executeUtility('{{ $utility['script'] }}', {{ json_encode($utility['parameters'] ?? []) }})" 
                      class="ml-4 px-3 py-1 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 transition-colors">
                🚀 Esegui
              </button>
              @endif
            </div>
            
            <p class="text-sm text-gray-600 mb-4">{{ $utility['description'] }}</p>

            <!-- Command Display -->
            <div class="mb-4">
              <label class="block text-xs font-medium text-gray-700 mb-1">Comando:</label>
              <div class="command-copy" onclick="copyToClipboard('{{ $utility['command'] }}')">
                {{ $utility['command'] }}
                <span class="float-right text-xs text-gray-500">📋 Clicca per copiare</span>
              </div>
            </div>

            <!-- Parameters -->
            @if(!empty($utility['parameters']))
            <div class="mb-4">
              <label class="block text-xs font-medium text-gray-700 mb-2">Parametri:</label>
              <div class="space-y-2">
                @foreach($utility['parameters'] as $param)
                <div class="flex items-start space-x-3">
                  <span class="parameter-badge {{ isset($param['required']) && $param['required'] ? 'param-required' : ($param['type'] === 'flag' ? 'param-flag' : 'param-optional') }}">
                    {{ isset($param['required']) && $param['required'] ? 'Required' : ($param['type'] === 'flag' ? 'Flag' : 'Optional') }}
                  </span>
                  <div class="flex-1">
                    <div class="flex items-center space-x-2">
                      <code class="text-sm font-medium text-gray-900">{{ $param['name'] }}</code>
                      <span class="text-xs text-gray-500">({{ $param['type'] }})</span>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">{{ $param['description'] }}</p>
                    @if(isset($param['example']))
                    <p class="text-xs text-blue-600 mt-1">Esempio: <code>{{ $param['example'] }}</code></p>
                    @endif
                  </div>
                </div>
                @endforeach
              </div>
            </div>
            @endif

            <!-- Examples -->
            @if(!empty($utility['examples']))
            <div class="mb-4">
              <label class="block text-xs font-medium text-gray-700 mb-2">Esempi di utilizzo:</label>
              <div class="space-y-1">
                @foreach($utility['examples'] as $example)
                <div class="command-copy text-xs" onclick="copyToClipboard('{{ $example }}')">
                  {{ $example }}
                  <span class="float-right text-gray-400">📋</span>
                </div>
                @endforeach
              </div>
            </div>
            @endif

            <!-- Note -->
            @if(isset($utility['note']))
            <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded">
              <p class="text-xs text-yellow-800">💡 <strong>Nota:</strong> {{ $utility['note'] }}</p>
            </div>
            @endif
          </div>
          @endforeach
        </div>
      </div>
    </div>
    @endforeach
  </div>

  <!-- Quick Actions -->
  <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
    <div class="p-6">
      <h2 class="text-xl font-semibold text-gray-900 mb-4">🚀 Azioni Rapide</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <button onclick="executeQuickAction('config:clear')" 
                class="p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-colors text-left">
          <div class="font-medium text-gray-900">🧹 Pulisci Config Cache</div>
          <div class="text-sm text-gray-600 mt-1">Pulisce cache configurazioni Laravel</div>
        </button>
        
        <button onclick="executeQuickAction('queue:restart')" 
                class="p-4 border border-gray-200 rounded-lg hover:border-green-300 hover:bg-green-50 transition-colors text-left">
          <div class="font-medium text-gray-900">🔄 Riavvia Code</div>
          <div class="text-sm text-gray-600 mt-1">Riavvia worker delle code</div>
        </button>

        <button onclick="executeQuickAction('rag:clear-cache')" 
                class="p-4 border border-gray-200 rounded-lg hover:border-purple-300 hover:bg-purple-50 transition-colors text-left">
          <div class="font-medium text-gray-900">🧠 Pulisci Cache RAG</div>
          <div class="text-sm text-gray-600 mt-1">Pulisce tutte le cache RAG</div>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Output Modal -->
<div id="output-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
  <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
    <div class="mt-3">
      <div class="flex justify-between items-center mb-4">
        <h3 class="text-lg font-medium text-gray-900">Output Comando</h3>
        <button onclick="closeOutputModal()" class="text-gray-400 hover:text-gray-600">
          <span class="sr-only">Chiudi</span>
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <div id="command-output" class="bg-gray-900 text-green-400 p-4 rounded font-mono text-sm max-h-96 overflow-auto whitespace-pre-wrap">
        <!-- Output will be loaded here -->
      </div>
      <div class="mt-4 flex justify-end">
        <button onclick="closeOutputModal()" 
                class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">
          Chiudi
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// CSRF Token
window.csrfToken = '{{ csrf_token() }}';

// Copy to clipboard
function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(function() {
    // Flash feedback
    const event = new CustomEvent('flash-message', { 
      detail: { message: 'Comando copiato negli appunti!', type: 'success' } 
    });
    window.dispatchEvent(event);
  }).catch(function(err) {
    console.error('Errore nel copiare:', err);
  });
}

// Execute quick action
function executeQuickAction(command) {
  executeCommand(command, {});
}

// Execute utility
function executeUtility(command, parameters) {
  // Per ora esegui senza parametri personalizzati
  // In futuro si può aggiungere un form per inserire i parametri
  executeCommand(command, {});
}

// Execute command
function executeCommand(command, parameters) {
  showOutputModal();
  document.getElementById('command-output').textContent = 'Esecuzione comando in corso...\n\n$ ' + command;

  fetch('{{ route('admin.utilities.execute') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': window.csrfToken
    },
    body: JSON.stringify({
      command: command,
      parameters: parameters
    })
  })
  .then(response => response.json())
  .then(data => {
    let output = '$ ' + command + '\n\n';
    
    if (data.success) {
      output += data.output || 'Comando eseguito con successo (nessun output)';
      output += '\n\n✅ Exit Code: ' + (data.exit_code || 0);
    } else {
      output += '❌ Errore: ' + (data.error || 'Errore sconosciuto');
    }
    
    document.getElementById('command-output').textContent = output;
  })
  .catch(error => {
    console.error('Errore:', error);
    document.getElementById('command-output').textContent = 
      '$ ' + command + '\n\n❌ Errore di rete: ' + error.message;
  });
}

// Modal management
function showOutputModal() {
  document.getElementById('output-modal').classList.remove('hidden');
}

function closeOutputModal() {
  document.getElementById('output-modal').classList.add('hidden');
}

// Flash message system (se non già presente)
window.addEventListener('flash-message', function(e) {
  const message = e.detail.message;
  const type = e.detail.type || 'info';
  
  // Crea un toast semplice
  const toast = document.createElement('div');
  toast.className = `fixed top-4 right-4 z-50 p-4 rounded shadow-lg ${
    type === 'success' ? 'bg-green-600 text-white' : 
    type === 'error' ? 'bg-red-600 text-white' : 
    'bg-blue-600 text-white'
  }`;
  toast.textContent = message;
  
  document.body.appendChild(toast);
  
  // Rimuovi dopo 3 secondi
  setTimeout(() => {
    if (toast.parentNode) {
      toast.parentNode.removeChild(toast);
    }
  }, 3000);
});
</script>

@endsection
