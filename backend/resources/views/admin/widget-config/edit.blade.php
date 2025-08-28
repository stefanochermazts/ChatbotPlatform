@extends('admin.layout')

@section('title', 'Modifica Widget ' . $tenant->name)

@section('content')
<div class="container mx-auto px-4 py-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-3xl font-bold text-gray-900">✏️ Modifica Widget {{ $tenant->name }}</h1>
      <p class="text-gray-600 mt-1">Personalizza aspetto e comportamento del chatbot</p>
    </div>
    
    <div class="flex gap-3">
      <a href="{{ route('admin.widget-config.show', $tenant) }}" class="btn btn-secondary">
        ← Indietro
      </a>
      <a href="{{ route('widget.preview', $tenant) }}" 
         class="btn btn-secondary" target="_blank">
        🔍 Preview
      </a>
    </div>
  </div>

  <form method="POST" action="{{ route('admin.widget-config.update', $tenant) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    
    <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
      <!-- Main Configuration -->
      <div class="xl:col-span-3 space-y-6">
        
        <!-- Basic Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">⚙️ Configurazione Base</h2>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label for="enabled" class="block text-sm font-medium text-gray-700">
                Stato Widget
              </label>
              <div class="mt-1">
                <label class="inline-flex items-center">
                  <input type="checkbox" name="enabled" id="enabled" value="1" 
                         @checked($config->enabled) class="rounded border-gray-300 text-blue-600">
                  <span class="ml-2 text-sm text-gray-900">Widget abilitato</span>
                </label>
              </div>
              @error('enabled')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="auto_open" class="block text-sm font-medium text-gray-700">
                Comportamento
              </label>
              <div class="mt-1">
                <label class="inline-flex items-center">
                  <input type="checkbox" name="auto_open" id="auto_open" value="1" 
                         @checked($config->auto_open) class="rounded border-gray-300 text-blue-600">
                  <span class="ml-2 text-sm text-gray-900">Apertura automatica</span>
                </label>
              </div>
              @error('auto_open')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
          </div>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
              <label for="widget_name" class="block text-sm font-medium text-gray-700">
                Nome Widget *
              </label>
              <input type="text" name="widget_name" id="widget_name" 
                     value="{{ old('widget_name', $config->widget_name) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     required>
              @error('widget_name')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="position" class="block text-sm font-medium text-gray-700">
                Posizione *
              </label>
              <select name="position" id="position" 
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @foreach($positions as $value => $label)
                  <option value="{{ $value }}" @selected(old('position', $config->position) === $value)>
                    {{ $label }}
                  </option>
                @endforeach
              </select>
              @error('position')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
          </div>
          
          <div class="mt-4">
            <label for="welcome_message" class="block text-sm font-medium text-gray-700">
              Messaggio di Benvenuto
            </label>
            <textarea name="welcome_message" id="welcome_message" rows="3"
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                      placeholder="Ciao! Sono l'assistente virtuale di {{ $tenant->name }}. Come posso aiutarti oggi?">{{ old('welcome_message', $config->welcome_message) }}</textarea>
            @error('welcome_message')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
          </div>
        </div>
        
        <!-- Theme Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">🎨 Configurazione Tema</h2>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label for="theme" class="block text-sm font-medium text-gray-700">
                Tema Predefinito *
              </label>
              <select name="theme" id="theme" 
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                      onchange="toggleCustomColors()">
                @foreach($themes as $value => $label)
                  <option value="{{ $value }}" @selected(old('theme', $config->theme) === $value)>
                    {{ $label }}
                  </option>
                @endforeach
                <option value="custom" @selected(old('theme', $config->theme) === 'custom')>
                  Personalizzato
                </option>
              </select>
              @error('theme')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="font_family" class="block text-sm font-medium text-gray-700">
                Font Family
              </label>
              <input type="text" name="font_family" id="font_family" 
                     value="{{ old('font_family', $config->font_family) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="'Inter', sans-serif">
              @error('font_family')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
          </div>
          
          <!-- Custom Colors -->
          <div id="customColors" class="mt-4 {{ $config->theme !== 'custom' ? 'hidden' : '' }}">
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Colori Personalizzati
            </label>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              @php $customColors = $config->custom_colors['primary'] ?? []; @endphp
              
              <div>
                <label class="block text-xs text-gray-600 mb-1">Primary 500 (Main)</label>
                <input type="color" name="custom_colors[primary][500]" 
                       value="{{ old('custom_colors.primary.500', $customColors['500'] ?? '#3b82f6') }}"
                       class="w-full h-10 border border-gray-300 rounded">
              </div>
              
              <div>
                <label class="block text-xs text-gray-600 mb-1">Primary 600 (Hover)</label>
                <input type="color" name="custom_colors[primary][600]" 
                       value="{{ old('custom_colors.primary.600', $customColors['600'] ?? '#2563eb') }}"
                       class="w-full h-10 border border-gray-300 rounded">
              </div>
              
              <div>
                <label class="block text-xs text-gray-600 mb-1">Primary 700 (Active)</label>
                <input type="color" name="custom_colors[primary][700]" 
                       value="{{ old('custom_colors.primary.700', $customColors['700'] ?? '#1d4ed8') }}"
                       class="w-full h-10 border border-gray-300 rounded">
              </div>
              
              <div>
                <label class="block text-xs text-gray-600 mb-1">Primary 50 (Light)</label>
                <input type="color" name="custom_colors[primary][50]" 
                       value="{{ old('custom_colors.primary.50', $customColors['50'] ?? '#eff6ff') }}"
                       class="w-full h-10 border border-gray-300 rounded">
              </div>
            </div>
          </div>
          
          <!-- Logo Upload -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
              <label for="logo_url" class="block text-sm font-medium text-gray-700">
                Logo URL
              </label>
              <input type="url" name="logo_url" id="logo_url" 
                     value="{{ old('logo_url', $config->logo_url) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="https://example.com/logo.png">
              @error('logo_url')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
              
              <div class="mt-2">
                <label for="logo_file" class="block text-xs text-gray-600">
                  Oppure carica file:
                </label>
                <input type="file" name="logo_file" id="logo_file" accept="image/*"
                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
              </div>
            </div>
            
            <div>
              <label for="favicon_url" class="block text-sm font-medium text-gray-700">
                Favicon URL
              </label>
              <input type="url" name="favicon_url" id="favicon_url" 
                     value="{{ old('favicon_url', $config->favicon_url) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="https://example.com/favicon.ico">
              @error('favicon_url')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
              
              <div class="mt-2">
                <label for="favicon_file" class="block text-xs text-gray-600">
                  Oppure carica file:
                </label>
                <input type="file" name="favicon_file" id="favicon_file" accept="image/*"
                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
              </div>
            </div>
          </div>
        </div>
        
        <!-- Layout Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">📐 Configurazione Layout</h2>
          
          <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
              <label for="widget_width" class="block text-sm font-medium text-gray-700">
                Larghezza Widget
              </label>
              <input type="text" name="widget_width" id="widget_width" 
                     value="{{ old('widget_width', $config->widget_width) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="380px">
              @error('widget_width')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="widget_height" class="block text-sm font-medium text-gray-700">
                Altezza Widget
              </label>
              <input type="text" name="widget_height" id="widget_height" 
                     value="{{ old('widget_height', $config->widget_height) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="600px">
              @error('widget_height')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="border_radius" class="block text-sm font-medium text-gray-700">
                Border Radius
              </label>
              <input type="text" name="border_radius" id="border_radius" 
                     value="{{ old('border_radius', $config->border_radius) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="1.5rem">
              @error('border_radius')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="button_size" class="block text-sm font-medium text-gray-700">
                Dimensione Pulsante
              </label>
              <input type="text" name="button_size" id="button_size" 
                     value="{{ old('button_size', $config->button_size) }}"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                     placeholder="60px">
              @error('button_size')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
          </div>
        </div>
        
        <!-- Behavior Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">🎛️ Configurazione Comportamento</h2>
          
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="show_header" value="1" 
                       @checked(old('show_header', $config->show_header)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">Mostra header</span>
              </label>
            </div>
            
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="show_avatar" value="1" 
                       @checked(old('show_avatar', $config->show_avatar)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">Mostra avatar</span>
              </label>
            </div>
            
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="show_close_button" value="1" 
                       @checked(old('show_close_button', $config->show_close_button)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">Pulsante chiudi</span>
              </label>
            </div>
            
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="enable_animations" value="1" 
                       @checked(old('enable_animations', $config->enable_animations)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">Animazioni</span>
              </label>
            </div>
            
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="enable_dark_mode" value="1" 
                       @checked(old('enable_dark_mode', $config->enable_dark_mode)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">Dark mode</span>
              </label>
            </div>
          </div>
        </div>
        
        <!-- API Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">🧠 Configurazione API</h2>
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label for="api_model" class="block text-sm font-medium text-gray-700">
                Modello AI *
              </label>
              <select name="api_model" id="api_model" 
                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                @foreach($models as $value => $label)
                  <option value="{{ $value }}" @selected(old('api_model', $config->api_model) === $value)>
                    {{ $label }}
                  </option>
                @endforeach
              </select>
              @error('api_model')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="temperature" class="block text-sm font-medium text-gray-700">
                Temperature *
              </label>
              <input type="number" name="temperature" id="temperature" 
                     value="{{ old('temperature', $config->temperature) }}"
                     min="0" max="2" step="0.1"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
              <div class="text-xs text-gray-500 mt-1">0 = deterministico, 2 = creativo</div>
              @error('temperature')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
            
            <div>
              <label for="max_tokens" class="block text-sm font-medium text-gray-700">
                Max Tokens *
              </label>
              <input type="number" name="max_tokens" id="max_tokens" 
                     value="{{ old('max_tokens', $config->max_tokens) }}"
                     min="1" max="4000"
                     class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
              <div class="text-xs text-gray-500 mt-1">Lunghezza massima risposta</div>
              @error('max_tokens')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
          </div>
          
          <div class="mt-4">
            <label class="inline-flex items-center">
              <input type="checkbox" name="enable_conversation_context" value="1" 
                     @checked(old('enable_conversation_context', $config->enable_conversation_context)) 
                     class="rounded border-gray-300 text-blue-600">
              <span class="ml-2 text-sm text-gray-900">Abilita contesto conversazionale</span>
            </label>
            <div class="text-xs text-gray-500 mt-1">Permette al widget di ricordare la conversazione per query di follow-up</div>
          </div>
        </div>
        
        <!-- Security Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">🔒 Configurazione Sicurezza</h2>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="enable_analytics" value="1" 
                       @checked(old('enable_analytics', $config->enable_analytics)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">Abilita analytics</span>
              </label>
            </div>
            
            <div>
              <label class="inline-flex items-center">
                <input type="checkbox" name="gdpr_compliant" value="1" 
                       @checked(old('gdpr_compliant', $config->gdpr_compliant)) 
                       class="rounded border-gray-300 text-blue-600">
                <span class="ml-2 text-sm text-gray-900">GDPR compliant</span>
              </label>
            </div>
          </div>
          
          <div class="mt-4">
            <label for="allowed_domains" class="block text-sm font-medium text-gray-700">
              Domini Consentiti
            </label>
            <div id="allowedDomains">
              @php $allowedDomains = old('allowed_domains', $config->allowed_domains ?? []); @endphp
              @if(empty($allowedDomains))
                @php $allowedDomains = ['']; @endphp
              @endif
              
              @foreach($allowedDomains as $index => $domain)
                <div class="flex mt-2 domain-row">
                  <input type="text" name="allowed_domains[]" value="{{ $domain }}"
                         class="flex-1 border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                         placeholder="example.com">
                  <button type="button" onclick="removeDomainRow(this)" 
                          class="ml-2 px-3 py-2 text-red-600 hover:text-red-800">
                    ✕
                  </button>
                </div>
              @endforeach
            </div>
            
            <button type="button" onclick="addDomainRow()" 
                    class="mt-2 text-sm text-blue-600 hover:text-blue-800">
              + Aggiungi dominio
            </button>
            <div class="text-xs text-gray-500 mt-1">Lascia vuoto per consentire tutti i domini</div>
          </div>
        </div>
        
        <!-- Custom Code -->
        <div class="bg-white rounded-lg shadow p-6">
          <h2 class="text-xl font-semibold mb-4">💻 Codice Personalizzato</h2>
          
          <div class="space-y-4">
            <div>
              <div class="flex justify-between items-center">
                <label for="custom_css" class="block text-sm font-medium text-gray-700">
                  CSS Personalizzato
                </label>
                <button type="button" onclick="loadCurrentColors()" 
                        class="inline-flex items-center px-3 py-1 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                  🎨 Carica Colori Attuali
                </button>
              </div>
              <textarea name="custom_css" id="custom_css" rows="6"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                        placeholder="/* CSS personalizzato per il widget */">{{ old('custom_css', $config->custom_css) }}</textarea>
              @error('custom_css')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
              
              <div class="mt-2 text-xs text-gray-600">
                💡 <strong>Tip:</strong> Clicca "Carica Colori Attuali" per ottenere un template CSS con tutti i colori predefiniti del widget pronti per la personalizzazione.
              </div>
            </div>
            
            <div>
              <label for="custom_js" class="block text-sm font-medium text-gray-700">
                JavaScript Personalizzato
              </label>
              <textarea name="custom_js" id="custom_js" rows="6"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"
                        placeholder="// JavaScript personalizzato per il widget">{{ old('custom_js', $config->custom_js) }}</textarea>
              @error('custom_js')<div class="text-red-600 text-sm mt-1">{{ $message }}</div>@enderror
            </div>
          </div>
        </div>
      </div>
      
      <!-- Sidebar -->
      <div class="space-y-6">
        
        <!-- Actions -->
        <div class="bg-white rounded-lg shadow p-6">
          <h3 class="text-lg font-semibold mb-4">💾 Azioni</h3>
          
          <div class="space-y-3">
            <button type="submit" class="w-full btn btn-primary">
              💾 Salva Configurazione
            </button>
            
            <button type="button" onclick="previewChanges()" class="w-full btn btn-secondary">
              👀 Anteprima Modifiche
            </button>
            
            <button type="button" onclick="resetForm()" class="w-full btn btn-secondary">
              🔄 Reset Modifiche
            </button>
          </div>
        </div>
        
        <!-- Preview -->
        <div class="bg-white rounded-lg shadow p-6">
          <h3 class="text-lg font-semibold mb-4">🔍 Anteprima</h3>
          
          <div class="text-center">
            <div class="bg-gray-100 rounded-lg p-4 mb-3">
              <div class="text-2xl mb-2">🤖</div>
              <div class="text-sm text-gray-600">
                L'anteprima del widget apparirà qui quando effettuerai delle modifiche
              </div>
            </div>
            
            <button type="button" onclick="openFullPreview()" class="text-sm text-blue-600 hover:text-blue-800">
              Apri anteprima completa →
            </button>
          </div>
        </div>
        
        <!-- Help -->
        <div class="bg-blue-50 rounded-lg p-6">
          <h3 class="text-lg font-semibold mb-4 text-blue-900">💡 Suggerimenti</h3>
          
          <div class="space-y-2 text-sm text-blue-800">
            <div>• Usa temi predefiniti per configurazioni rapide</div>
            <div>• Il tema personalizzato permette massima flessibilità</div>
            <div>• L'apertura automatica funziona bene per supporto attivo</div>
            <div>• Temperature basse (0.3) per risposte più precise</div>
            <div>• Il contesto conversazionale migliora follow-up</div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
function toggleCustomColors() {
  const theme = document.getElementById('theme').value;
  const customColors = document.getElementById('customColors');
  
  if (theme === 'custom') {
    customColors.classList.remove('hidden');
  } else {
    customColors.classList.add('hidden');
  }
}

function addDomainRow() {
  const container = document.getElementById('allowedDomains');
  const newRow = document.createElement('div');
  newRow.className = 'flex mt-2 domain-row';
  newRow.innerHTML = `
    <input type="text" name="allowed_domains[]" value=""
           class="flex-1 border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
           placeholder="example.com">
    <button type="button" onclick="removeDomainRow(this)" 
            class="ml-2 px-3 py-2 text-red-600 hover:text-red-800">
      ✕
    </button>
  `;
  container.appendChild(newRow);
}

function removeDomainRow(button) {
  const row = button.closest('.domain-row');
  row.remove();
}

function previewChanges() {
  // Collect form data
  const formData = new FormData(document.querySelector('form'));
  const config = {};
  
  for (let [key, value] of formData.entries()) {
    config[key] = value;
  }
  
  // Open preview with current config
  const previewUrl = '{{ route('widget.preview', $tenant) }}?' + 
                     new URLSearchParams({preview_config: JSON.stringify(config)});
  window.open(previewUrl, '_blank');
}

function resetForm() {
  if (confirm('Sei sicuro di voler resettare tutte le modifiche?')) {
    document.querySelector('form').reset();
    toggleCustomColors();
  }
}

function openFullPreview() {
  window.open('{{ route('widget.preview', $tenant) }}', '_blank');
}

async function loadCurrentColors() {
  const textarea = document.getElementById('custom_css');
  const button = event.target;
  
  // Confirm if textarea already has content
  if (textarea.value.trim() && !confirm('Questo sovrascriverà il CSS esistente. Continuare?')) {
    return;
  }
  
  try {
    // Show loading state
    button.disabled = true;
    button.innerHTML = '⏳ Caricando...';
    
    const response = await fetch('{{ route('admin.widget-config.current-colors', $tenant) }}');
    const data = await response.json();
    
    if (data.success) {
      textarea.value = data.css;
      
      // Show success message
      button.innerHTML = '✅ Caricato!';
      setTimeout(() => {
        button.innerHTML = '🎨 Carica Colori Attuali';
        button.disabled = false;
      }, 2000);
      
      // Scroll to textarea to show the result
      textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
      
    } else {
      throw new Error(data.error || 'Errore durante il caricamento');
    }
    
  } catch (error) {
    console.error('Error loading colors:', error);
    
    button.innerHTML = '❌ Errore';
    setTimeout(() => {
      button.innerHTML = '🎨 Carica Colori Attuali';
      button.disabled = false;
    }, 2000);
    
    alert('Errore durante il caricamento dei colori: ' + error.message);
  }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
  toggleCustomColors();
});
</script>

<style>
  .btn {
    @apply inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition;
  }
  
  .btn-primary {
    @apply text-white bg-blue-600 hover:bg-blue-700 focus:ring-blue-500;
  }
  
  .btn-secondary {
    @apply text-gray-700 bg-white border-gray-300 hover:bg-gray-50 focus:ring-blue-500;
  }
</style>
@endsection
