@extends('admin.layout')

@section('content')
<div class="grid gap-6">
  <!-- Quick Navigation -->
  <div class="bg-white border rounded p-4">
    <h1 class="text-xl font-semibold mb-4">Modifica Cliente: {{ $tenant->name }}</h1>
    <div class="flex flex-wrap gap-3">
      <a href="{{ route('admin.documents.index', $tenant) }}" class="inline-flex items-center px-3 py-2 bg-indigo-100 text-indigo-700 rounded text-sm hover:bg-indigo-200">
        📄 Documenti
      </a>
      <a href="{{ route('admin.scraper.edit', $tenant) }}" class="inline-flex items-center px-3 py-2 bg-emerald-100 text-emerald-700 rounded text-sm hover:bg-emerald-200">
        🕷️ Scraper
      </a>
      <a href="{{ route('admin.rag-config.show', $tenant) }}" class="inline-flex items-center px-3 py-2 bg-purple-100 text-purple-700 rounded text-sm hover:bg-purple-200">
        🎛️ RAG Config
      </a>
      <a href="{{ route('admin.tenants.feedback.index', $tenant) }}" class="inline-flex items-center px-3 py-2 bg-amber-100 text-amber-700 rounded text-sm hover:bg-amber-200">
        📝 Feedback
      </a>
      <a href="{{ route('admin.tenants.index') }}" class="inline-flex items-center px-3 py-2 bg-gray-100 text-gray-700 rounded text-sm hover:bg-gray-200">
        ← Torna ai Clienti
      </a>
    </div>
  </div>

  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Informazioni di Base</h2>
    
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4">
      @csrf @method('put')
      
      <!-- Informazioni base -->
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Nome *</span>
          <input name="name" value="{{ old('name', $tenant->name) }}" class="w-full border rounded px-3 py-2 mt-1" required />
        </label>
        <label class="block">
          <span class="text-sm font-medium">Slug *</span>
          <input name="slug" value="{{ old('slug', $tenant->slug) }}" class="w-full border rounded px-3 py-2 mt-1" required />
        </label>
      </div>
      
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Dominio (opzionale)</span>
          <input name="domain" value="{{ old('domain', $tenant->domain) }}" class="w-full border rounded px-3 py-2 mt-1" />
        </label>
        <label class="block">
          <span class="text-sm font-medium">Piano (opzionale)</span>
          <input name="plan" value="{{ old('plan', $tenant->plan) }}" class="w-full border rounded px-3 py-2 mt-1" />
        </label>
      </div>
      
      <!-- Configurazione lingua -->
      <div class="grid md:grid-cols-2 gap-4">
        <label class="block">
          <span class="text-sm font-medium">Lingue supportate</span>
          <input name="languages" value="{{ old('languages', implode(',', (array)($tenant->languages ?? []))) }}" class="w-full border rounded px-3 py-2 mt-1" placeholder="it,en,fr" />
          <small class="text-gray-600">Codici ISO separati da virgola (es: it,en,fr)</small>
        </label>
        <label class="block">
          <span class="text-sm font-medium">Lingua predefinita</span>
          <input name="default_language" value="{{ old('default_language', $tenant->default_language) }}" class="w-full border rounded px-3 py-2 mt-1" placeholder="it" />
        </label>
      </div>
      
      <!-- Prompts personalizzati -->
      <div class="border-t pt-4">
        <h3 class="font-medium mb-3">Prompts Personalizzati</h3>
        <div class="grid gap-4">
          <label class="block">
            <span class="text-sm font-medium">Prompt di sistema personalizzato</span>
            <textarea name="custom_system_prompt" rows="3" class="w-full border rounded px-3 py-2 mt-1" placeholder="Es: Sei un assistente specializzato per il customer service...">{{ old('custom_system_prompt', $tenant->custom_system_prompt) }}</textarea>
            <small class="text-gray-600">Definisce il comportamento del chatbot in ogni conversazione.</small>
          </label>
          <label class="block">
            <span class="text-sm font-medium">Template del contesto KB</span>
            <textarea name="custom_context_template" rows="2" class="w-full border rounded px-3 py-2 mt-1" placeholder="Es: Utilizza queste informazioni dalla knowledge base: {context}">{{ old('custom_context_template', $tenant->custom_context_template) }}</textarea>
            <small class="text-gray-600">Personalizza la presentazione del contesto. Usa {context} come placeholder.</small>
          </label>
        </div>
      </div>
      
      <div class="flex justify-end pt-4">
        <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salva Modifiche</button>
      </div>
    </form>
  </div>

  <!-- Configurazione Intent Avanzata -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Configurazione Intent Avanzata</h2>
    <div class="bg-blue-50 border border-blue-200 rounded p-4 mb-4">
      <h3 class="font-medium text-blue-800 mb-2">ℹ️ Nuova Interfaccia Disponibile</h3>
      <p class="text-sm text-blue-700 mb-3">
        Le configurazioni degli intent (abilitazione, soglie, modalità KB) sono ora gestite nella nuova interfaccia RAG unificata.
      </p>
      <a href="{{ route('admin.rag-config.show', $tenant) }}" 
         class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
        🎛️ Vai alla Configurazione RAG
      </a>
    </div>
    
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4">
      @csrf @method('put')
      
      <label class="block">
        <span class="text-sm font-medium">Parole chiave aggiuntive (JSON)</span>
        <textarea name="extra_intent_keywords" rows="4" class="w-full border rounded px-3 py-2 mt-1 font-mono text-sm" placeholder='{"phone":["centralino"],"schedule":["ricevimento"]}'>{{ old('extra_intent_keywords', json_encode($tenant->extra_intent_keywords ?? new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) }}</textarea>
        <small class="text-gray-600">Dizionario JSON con array di parole chiave aggiuntive per ogni intent.</small>
      </label>
      
      <div class="flex justify-end pt-2">
        <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salva Parole Chiave</button>
      </div>
    </form>
  </div>

  <!-- Sinonimi Personalizzati -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Sinonimi Personalizzati</h2>
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4">
      @csrf @method('put')
      
      <div class="bg-blue-50 border border-blue-200 rounded p-4 mb-4">
        <h3 class="font-medium text-blue-800 mb-2">ℹ️ Come Funzionano i Sinonimi</h3>
        <p class="text-sm text-blue-700 mb-2">
          I sinonimi migliorano la ricerca permettendo al sistema di trovare risultati anche quando l'utente usa terminologia diversa.
        </p>
        <p class="text-sm text-blue-700">
          <strong>Esempio:</strong> Se configuri <code>"vigili" → "polizia locale municipale"</code>, 
          una ricerca per "vigili urbani" troverà anche documenti che parlano di "polizia locale".
        </p>
      </div>
      
      <label class="block">
        <span class="text-sm font-medium">Configurazione Sinonimi (JSON)</span>
        <textarea name="custom_synonyms" rows="10" class="w-full border rounded px-3 py-2 mt-1 font-mono text-sm" placeholder='{"vigili urbani": "polizia locale municipale", "comune": "municipio ufficio comunale"}'>{{ old('custom_synonyms', json_encode($tenant->custom_synonyms ?? new stdClass(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) }}</textarea>
        <small class="text-gray-600">
          Formato: <code>{"termine_originale": "sinonimi alternativi"}</code>. 
          Se vuoto, verranno usati i sinonimi di default del sistema.
        </small>
      </label>
      
      <div class="bg-gray-50 border rounded p-4">
        <h4 class="font-medium mb-2">📋 Sinonimi di Default del Sistema</h4>
        <div class="text-xs text-gray-600 space-y-1">
          <div><strong>Servizi Pubblici:</strong> vigili urbani, polizia locale, comune, municipio, anagrafe</div>
          <div><strong>Sanità:</strong> pronto soccorso, ospedale, asl, guardia medica</div>
          <div><strong>Trasporti:</strong> stazione, fermata, parcheggio, ztl</div>
          <div><strong>Istruzione:</strong> scuola, università, biblioteca</div>
          <div><strong>Geografia:</strong> centro storico, periferia, frazione</div>
          <div class="mt-2">
            <em>Questi sinonimi vengono applicati automaticamente se il campo sopra è vuoto.</em>
          </div>
        </div>
      </div>
      
      <div class="flex justify-end pt-2">
        <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salva Sinonimi</button>
      </div>
    </form>
  </div>

  <!-- Configurazione Knowledge Base -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Configurazione Knowledge Base</h2>
    <form method="post" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4">
      @csrf @method('put')
      
      <div class="bg-yellow-50 border border-yellow-200 rounded p-4 mb-4">
        <h3 class="font-medium text-yellow-800 mb-2">🔍 Ricerca Multi-KB</h3>
        <p class="text-sm text-yellow-700 mb-2">
          Per default, il sistema sceglie automaticamente la Knowledge Base migliore per ogni query.
        </p>
        <p class="text-sm text-yellow-700">
          <strong>Ricerca Multi-KB:</strong> Se abilitata, il sistema cercherà simultaneamente in TUTTE le Knowledge Base del tenant, 
          permettendo di trovare informazioni anche se sono distribuite in KB diverse (es. "Documenti" + "Sito").
        </p>
      </div>
      
      <label class="flex items-center gap-3">
        <input type="hidden" name="multi_kb_search" value="0">
        <input type="checkbox" name="multi_kb_search" value="1" 
               {{ old('multi_kb_search', $tenant->multi_kb_search) ? 'checked' : '' }} 
               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
        <div>
          <span class="text-sm font-medium">Abilita ricerca in tutte le Knowledge Base</span>
          <div class="text-xs text-gray-600">
            Permette di estrarre informazioni da multiple KB invece che solo dalla migliore
          </div>
        </div>
      </label>
      
      <label class="flex items-center gap-3">
        <input type="hidden" name="js_rendering_enabled" value="0">
        <input type="checkbox" name="js_rendering_enabled" value="1" 
               {{ old('js_rendering_enabled', $tenant->js_rendering_enabled) ? 'checked' : '' }} 
               class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
        <div>
          <span class="text-sm font-medium">🌐 Abilita JavaScript Rendering (Scraper Avanzato)</span>
          <div class="text-xs text-gray-600">
            Permette al scraper di elaborare siti JavaScript/SPA (Angular, React, Vue). 
            <strong>⚠️ Aumenta tempi e costi di scraping</strong>
          </div>
        </div>
      </label>
      
      <div class="flex justify-end pt-2">
        <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Salva Configurazione KB</button>
      </div>
    </form>
  </div>

  <!-- Knowledge Bases -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Knowledge Bases</h2>
    
    <!-- Form creazione KB -->
    <form method="post" action="{{ route('admin.tenants.kb.store', $tenant) }}" class="border-b pb-4 mb-4">
      @csrf
      <div class="grid md:grid-cols-4 gap-3 items-end">
        <label class="block">
          <span class="text-sm font-medium">Nome KB *</span>
          <input name="name" class="w-full border rounded px-3 py-2 mt-1" required />
        </label>
        <label class="block md:col-span-2">
          <span class="text-sm font-medium">Descrizione</span>
          <input name="description" class="w-full border rounded px-3 py-2 mt-1" />
        </label>
        <div class="flex items-center justify-between">
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_default" value="1" />
            <span class="text-sm">Default</span>
          </label>
          <button class="px-3 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700">Aggiungi</button>
        </div>
      </div>
    </form>
    
    <!-- Lista KB esistenti -->
    @php($kbs = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->orderBy('id')->get())
    @if($kbs->isEmpty())
      <div class="text-sm text-gray-600 py-4">Nessuna Knowledge Base presente. Creane una per iniziare.</div>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 text-left">
              <th class="p-3 font-medium">ID</th>
              <th class="p-3 font-medium">Nome</th>
              <th class="p-3 font-medium">Descrizione</th>
              <th class="p-3 font-medium">Default</th>
              <th class="p-3 font-medium">Azioni</th>
            </tr>
          </thead>
          <tbody>
            @foreach($kbs as $kb)
              <tr class="border-t">
                <td class="p-3">{{ $kb->id }}</td>
                <td class="p-3">
                  <form method="post" action="{{ route('admin.tenants.kb.update', [$tenant, $kb]) }}" class="inline-flex items-center gap-2">
                    @csrf @method('put')
                    <input name="name" value="{{ $kb->name }}" class="border rounded px-2 py-1 text-sm min-w-32" />
                </td>
                <td class="p-3">
                    <input name="description" value="{{ $kb->description }}" class="border rounded px-2 py-1 text-sm w-full" />
                </td>
                <td class="p-3">
                    <label class="inline-flex items-center gap-1">
                      <input type="checkbox" name="is_default" value="1" {{ $kb->is_default ? 'checked' : '' }} />
                      <span class="text-xs">Default</span>
                    </label>
                </td>
                <td class="p-3">
                  <div class="flex gap-2">
                    <button class="px-2 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">Salva</button>
                  </form>
                  <form method="post" action="{{ route('admin.tenants.kb.destroy', [$tenant, $kb]) }}" onsubmit="return confirm('Eliminare la KB? I documenti resteranno senza KB.')" class="inline">
                    @csrf @method('delete')
                    <button class="px-2 py-1 bg-rose-600 text-white rounded text-sm hover:bg-rose-700">Elimina</button>
                  </form>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <!-- API Keys Management -->
  <div class="bg-white border rounded p-6">
    <h2 class="text-lg font-semibold mb-4">🔑 Gestione API Keys</h2>
    
    <!-- Alert per nuova API key -->
    @if(session('api_key'))
      <div class="bg-green-50 border border-green-200 rounded p-4 mb-6">
        <div class="flex items-start gap-3">
          <div class="text-green-600">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
            </svg>
          </div>
          <div class="flex-1">
            <h3 class="font-medium text-green-800">API Key creata con successo!</h3>
            <p class="text-sm text-green-700 mt-1">
              <strong>Nome:</strong> {{ session('api_key_name') }}
            </p>
            <div class="mt-3 p-3 bg-white border rounded">
              <p class="text-sm font-medium text-gray-700 mb-2">⚠️ Copia questa API key ora - non sarà più visibile:</p>
              <div class="flex items-center gap-2">
                <code class="flex-1 px-3 py-2 bg-gray-50 border rounded text-sm font-mono break-all">{{ session('api_key') }}</code>
                <button onclick="copyToClipboard('{{ session('api_key') }}')" class="px-3 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                  Copia
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    @endif
    
    <!-- Form creazione API Key -->
    <form method="post" action="{{ route('admin.tenants.api-keys.create', $tenant) }}" class="border-b pb-4 mb-4">
      @csrf
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <label class="block">
          <span class="text-sm font-medium">Nome API Key *</span>
          <input name="name" class="w-full border rounded px-3 py-2 mt-1" placeholder="Es: Widget API Key" required />
        </label>
        <label class="block">
          <span class="text-sm font-medium">Scopes (opzionale)</span>
          <div class="flex flex-wrap gap-2 mt-1">
            <label class="inline-flex items-center gap-1">
              <input type="checkbox" name="scopes[]" value="chat" checked />
              <span class="text-sm">Chat</span>
            </label>
            <label class="inline-flex items-center gap-1">
              <input type="checkbox" name="scopes[]" value="documents" />
              <span class="text-sm">Documents</span>
            </label>
            <label class="inline-flex items-center gap-1">
              <input type="checkbox" name="scopes[]" value="analytics" />
              <span class="text-sm">Analytics</span>
            </label>
          </div>
        </label>
        <div>
          <button class="w-full px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700">
            🔑 Crea API Key
          </button>
        </div>
      </div>
    </form>
    
    <!-- Lista API Keys esistenti -->
    @php($apiKeys = $tenant->apiKeys()->orderBy('created_at', 'desc')->get())
    @if($apiKeys->isEmpty())
      <div class="text-center py-8 text-gray-500">
        <div class="text-4xl mb-2">🔐</div>
        <p class="text-sm">Nessuna API Key presente.</p>
        <p class="text-xs text-gray-400 mt-1">Crea la prima API Key per abilitare l'integrazione del widget.</p>
      </div>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 text-left">
              <th class="p-3 font-medium">Nome</th>
              <th class="p-3 font-medium">Scopes</th>
              <th class="p-3 font-medium">Stato</th>
              <th class="p-3 font-medium">Creata</th>
              <th class="p-3 font-medium">Azioni</th>
            </tr>
          </thead>
          <tbody>
            @foreach($apiKeys as $apiKey)
              <tr class="border-t {{ $apiKey->revoked_at ? 'bg-red-50' : '' }}">
                <td class="p-3">
                  <div class="font-medium">{{ $apiKey->name }}</div>
                  <div class="text-xs text-gray-500">ID: {{ $apiKey->id }}</div>
                </td>
                <td class="p-3">
                  @if($apiKey->scopes)
                    <div class="flex flex-wrap gap-1">
                      @foreach($apiKey->scopes as $scope)
                        <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">{{ $scope }}</span>
                      @endforeach
                    </div>
                  @else
                    <span class="text-gray-400 text-xs">Nessun scope</span>
                  @endif
                </td>
                <td class="p-3">
                  @if($apiKey->revoked_at)
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-red-100 text-red-800 rounded text-xs">
                      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M13.477 14.89A6 6 0 015.11 6.524l8.367 8.368zm1.414-1.414L6.524 5.11a6 6 0 018.367 8.367zM18 10a8 8 0 11-16 0 8 8 0 0116 0z" clip-rule="evenodd"></path>
                      </svg>
                      Revocata
                    </span>
                    <div class="text-xs text-gray-500 mt-1">{{ $apiKey->revoked_at->format('d/m/Y H:i') }}</div>
                  @else
                    <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-100 text-green-800 rounded text-xs">
                      <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                      </svg>
                      Attiva
                    </span>
                  @endif
                </td>
                <td class="p-3">
                  <div class="text-sm">{{ $apiKey->created_at->format('d/m/Y') }}</div>
                  <div class="text-xs text-gray-500">{{ $apiKey->created_at->format('H:i') }}</div>
                </td>
                <td class="p-3">
                  @if(!$apiKey->revoked_at)
                    <form method="post" action="{{ route('admin.tenants.api-keys.revoke', [$tenant, $apiKey->id]) }}" 
                          onsubmit="return confirm('Sei sicuro di voler revocare questa API Key? L\'azione non è reversibile.')" 
                          class="inline">
                      @csrf @method('delete')
                      <button class="px-2 py-1 bg-red-600 text-white rounded text-xs hover:bg-red-700">
                        🗑️ Revoca
                      </button>
                    </form>
                  @else
                    <span class="text-gray-400 text-xs">Già revocata</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      
      <!-- Info utilizzo -->
      <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded">
        <h4 class="font-medium text-blue-800 mb-2">💡 Come utilizzare le API Key</h4>
        <div class="text-sm text-blue-700 space-y-1">
          <p><strong>Per il widget:</strong> Copia l'API Key e utilizzala nella configurazione del widget.</p>
          <p><strong>Per le API:</strong> Includi l'header <code>Authorization: Bearer &lt;API_KEY&gt;</code> nelle chiamate.</p>
          <p><strong>Endpoint principale:</strong> <code>{{ config('app.url') }}/api/v1/chat/completions</code></p>
        </div>
      </div>
    @endif
  </div>
</div>

<script>
function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(function() {
    // Mostra feedback visivo
    event.target.textContent = '✓ Copiato!';
    event.target.classList.remove('bg-blue-600', 'hover:bg-blue-700');
    event.target.classList.add('bg-green-600');
    setTimeout(() => {
      event.target.textContent = 'Copia';
      event.target.classList.remove('bg-green-600');
      event.target.classList.add('bg-blue-600', 'hover:bg-blue-700');
    }, 2000);
  });
}
</script>

@endsection

