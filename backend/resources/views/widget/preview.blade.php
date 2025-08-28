<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Preview Widget - {{ $tenant->name }}</title>
  
  <!-- Preview Page Styles -->
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      margin: 0;
      padding: 20px;
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
    }
    
    .preview-container {
      max-width: 1200px;
      margin: 0 auto;
    }
    
    .preview-header {
      background: white;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .preview-content {
      background: white;
      border-radius: 12px;
      padding: 40px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      min-height: 500px;
      position: relative;
    }
    
    .preview-info {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    
    .info-card {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 8px;
      border-left: 4px solid #007bff;
    }
    
    .info-label {
      font-size: 12px;
      font-weight: 600;
      color: #6c757d;
      text-transform: uppercase;
      margin-bottom: 5px;
    }
    
    .info-value {
      font-size: 14px;
      color: #495057;
      font-weight: 500;
    }
    
    .preview-controls {
      position: fixed;
      top: 20px;
      right: 20px;
      background: white;
      border-radius: 8px;
      padding: 15px;
      box-shadow: 0 2px 15px rgba(0,0,0,0.2);
      z-index: 9999;
      min-width: 200px;
    }
    
    .control-btn {
      display: block;
      width: 100%;
      padding: 8px 12px;
      margin-bottom: 8px;
      background: #007bff;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
      text-decoration: none;
      text-align: center;
      transition: background 0.2s;
    }
    
    .control-btn:hover {
      background: #0056b3;
      color: white;
    }
    
    .control-btn.secondary {
      background: #6c757d;
    }
    
    .control-btn.secondary:hover {
      background: #545b62;
    }
    
    .device-frame {
      position: relative;
      margin: 0 auto;
      max-width: 400px;
    }
    
    .device-frame.mobile {
      max-width: 375px;
    }
    
    .device-frame.tablet {
      max-width: 768px;
    }
    
    .device-frame.desktop {
      max-width: 1200px;
    }
    
    @media (max-width: 768px) {
      .preview-controls {
        position: relative;
        top: auto;
        right: auto;
        margin-bottom: 20px;
      }
      
      .preview-content {
        padding: 20px;
      }
    }
  </style>
</head>
<body>
  <div class="preview-container">
    <!-- Header -->
    <div class="preview-header">
      <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
          <h1 style="margin: 0; font-size: 24px; color: #333;">🔍 Preview Widget - {{ $tenant->name }}</h1>
          <p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">Configurazione: {{ $config->theme }} | Posizione: {{ $config->position }}</p>
        </div>
        <div style="display: flex; gap: 10px;">
          <a href="{{ route('admin.widget-config.edit', $tenant) }}" class="control-btn secondary">✏️ Modifica</a>
          <a href="{{ route('admin.widget-config.show', $tenant) }}" class="control-btn">← Indietro</a>
        </div>
      </div>
    </div>
    
    <!-- Preview Controls -->
    <div class="preview-controls">
      <div style="font-weight: 600; margin-bottom: 10px; font-size: 14px;">🎛️ Controlli</div>
      
      <button onclick="toggleWidget()" class="control-btn">🤖 Apri/Chiudi Widget</button>
      <button onclick="resetWidget()" class="control-btn secondary">🔄 Reset Chat</button>
      <button onclick="changeDevice('mobile')" class="control-btn secondary">📱 Vista Mobile</button>
      <button onclick="changeDevice('tablet')" class="control-btn secondary">📷 Vista Tablet</button>
      <button onclick="changeDevice('desktop')" class="control-btn secondary">🖥️ Vista Desktop</button>
      
      <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
        <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Status Widget:</div>
        <div id="widgetStatus" style="font-size: 12px; font-weight: 600;">🟡 Caricamento...</div>
      </div>
    </div>
    
    <!-- Widget Configuration Info -->
    <div class="preview-info">
      <div class="info-card">
        <div class="info-label">Nome Widget</div>
        <div class="info-value">{{ $config->widget_name }}</div>
      </div>
      
      <div class="info-card">
        <div class="info-label">Tema</div>
        <div class="info-value">{{ ucfirst($config->theme) }}</div>
      </div>
      
      <div class="info-card">
        <div class="info-label">Posizione</div>
        <div class="info-value">{{ ucwords(str_replace('-', ' ', $config->position)) }}</div>
      </div>
      
      <div class="info-card">
        <div class="info-label">Modello API</div>
        <div class="info-value">{{ $config->api_model }}</div>
      </div>
      
      <div class="info-card">
        <div class="info-label">Auto Open</div>
        <div class="info-value">{{ $config->auto_open ? '✅ Sì' : '❌ No' }}</div>
      </div>
      
      <div class="info-card">
        <div class="info-label">Conversation Context</div>
        <div class="info-value">{{ $config->enable_conversation_context ? '✅ Abilitato' : '❌ Disabilitato' }}</div>
      </div>
      
      <div class="info-card">
        <div class="info-label">API Key</div>
        <div class="info-value">{{ $apiKey ? '✅ Configurata (' . substr($apiKey, 0, 8) . '...)' : '❌ Non trovata' }}</div>
      </div>
    </div>
    
    <!-- Preview Content -->
    <div class="preview-content">
      <div id="deviceFrame" class="device-frame desktop">
        <div style="text-align: center; color: #666; margin-bottom: 30px;">
          <h2>🌐 Sito Web di Esempio</h2>
          <p>Questo è un esempio di come il widget apparirà sul sito del cliente.</p>
          <p><strong>Scorri verso il basso per vedere il widget flottante →</strong></p>
        </div>
        
        <!-- Fake website content -->
        <div style="margin-bottom: 40px; line-height: 1.6; color: #333;">
          <h3>Benvenuto su {{ $tenant->name }}</h3>
          <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris.</p>
          
          <h4>I nostri servizi</h4>
          <ul>
            <li>Assistenza clienti 24/7</li>
            <li>Supporto tecnico specializzato</li>
            <li>Consulenza personalizzata</li>
            <li>Soluzioni innovative</li>
          </ul>
          
          <p>Nulla facilisi morbi tempus iaculis urna id volutpat lacus. Ornare arcu odio ut sem nulla pharetra diam sit amet. Duis at consectetur lorem donec massa sapien faucibus et molestie.</p>
          
          <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h4 style="margin-top: 0;">💬 Hai bisogno di aiuto?</h4>
            <p style="margin-bottom: 0;">Il nostro assistente virtuale è sempre disponibile per rispondere alle tue domande. Clicca sull'icona del chat in basso a destra per iniziare!</p>
          </div>
          
          <p>Vestibulum ante ipsum primis in faucibus orci luctus et ultrices posuere cubilia curae; Donec velit neque, auctor sit amet aliquam vel, ullamcorper sit amet ligula.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Widget Configuration Script -->
  <script>
    // Widget configuration from backend
    window.chatbotConfig = @json($config->embed_config);
    
    // Set the real API key for this tenant
    @if($apiKey)
      window.chatbotConfig.apiKey = '{{ $apiKey }}';
    @else
      window.chatbotConfig.apiKey = 'demo_api_key_123'; // Fallback demo key
      console.warn('⚠️ Nessuna API key trovata per questo tenant. Usando chiave demo.');
    @endif
    
    window.chatbotConfig.debug = true;
    
    // Preview specific settings
    window.chatbotConfig.autoOpen = false; // Don't auto-open in preview
    
    // Preview state
    let widgetLoaded = false;
    let currentDevice = 'desktop';
    
    // Update status
    function updateStatus(message, color = '#666') {
      const status = document.getElementById('widgetStatus');
      status.textContent = message;
      status.style.color = color;
    }
    
    // Widget controls
    function toggleWidget() {
      if (window.chatbotWidget) {
        window.chatbotWidget.toggle();
      } else {
        updateStatus('⚠️ Widget non caricato', '#dc3545');
      }
    }
    
    function resetWidget() {
      if (window.chatbotWidget) {
        window.chatbotWidget.reset();
        updateStatus('🔄 Widget resettato', '#28a745');
      }
    }
    
    function changeDevice(device) {
      currentDevice = device;
      const frame = document.getElementById('deviceFrame');
      frame.className = `device-frame ${device}`;
      
      // Simulate device-specific behavior
      if (device === 'mobile') {
        updateStatus('📱 Vista Mobile', '#007bff');
      } else if (device === 'tablet') {
        updateStatus('📷 Vista Tablet', '#007bff');
      } else {
        updateStatus('🖥️ Vista Desktop', '#007bff');
      }
    }
    
    // Widget event listeners
    window.addEventListener('chatbot:embed:loaded', function(event) {
      widgetLoaded = true;
      updateStatus('✅ Widget Caricato', '#28a745');
      console.log('Widget loaded successfully:', event.detail);
    });
    
    window.addEventListener('chatbot:embed:error', function(event) {
      updateStatus('❌ Errore Caricamento', '#dc3545');
      console.error('Widget error:', event.detail);
    });
    
    window.addEventListener('chatbot:widget:opened', function() {
      updateStatus('💬 Widget Aperto', '#007bff');
    });
    
    window.addEventListener('chatbot:widget:closed', function() {
      updateStatus(widgetLoaded ? '✅ Widget Caricato' : '🟡 Caricamento...', widgetLoaded ? '#28a745' : '#ffc107');
    });
    
    window.addEventListener('chatbot:message:sent', function(event) {
      console.log('Message sent:', event.detail);
    });
    
    window.addEventListener('chatbot:message:received', function(event) {
      console.log('Message received:', event.detail);
    });
    
    // Apply custom theme if available
    @if($config->custom_css)
      const customStyle = document.createElement('style');
      customStyle.textContent = `{{ $config->custom_css }}`;
      document.head.appendChild(customStyle);
    @endif
    
    // Initialize
    updateStatus('🟡 Caricamento Widget...', '#ffc107');
  </script>

  <!-- Load Widget -->
  <script src="{{ asset('widget/embed/chatbot-embed.js') }}" async></script>
</body>
</html>
