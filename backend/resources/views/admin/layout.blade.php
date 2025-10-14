<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Admin - ChatbotPlatform</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  
  <!-- Laravel Echo for WebSocket -->
  <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.3/dist/echo.iife.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <nav class="bg-white border-b border-gray-200">
    <div class="w-full px-4 sm:px-6 py-3 flex items-center justify-between">
      <div class="flex items-center space-x-4">
        <a href="{{ route('admin.dashboard') }}" class="font-semibold">Admin Panel</a>
        @auth
          <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
        @endauth
      </div>
      <div class="flex gap-4">
        @if(auth()->user()->isAdmin())
          <a class="hover:text-blue-600" href="{{ route('admin.tenants.index') }}">Clienti</a>
          <a class="hover:text-blue-600" href="{{ route('admin.users.index') }}">👥 Utenti</a>
        @endif
        <a class="hover:text-blue-600" href="{{ route('admin.forms.index') }}">📝 Form</a>
        <a class="hover:text-blue-600" href="{{ route('admin.widget-config.index') }}">Widget</a>
        <a class="hover:text-blue-600" href="{{ route('admin.whatsapp-config.index') }}">📱 WhatsApp</a>
        <a class="hover:text-blue-600" href="{{ route('admin.widget-analytics.index') }}">Analytics</a>
        <a class="hover:text-blue-600" href="{{ route('admin.rag.index') }}">RAG Tester</a>
        <a class="hover:text-blue-600" href="{{ route('admin.operator-console.index') }}">👨‍💼 Operator Console</a>
        @if(auth()->user()->isAdmin())
          <a class="hover:text-blue-600" href="{{ route('admin.utilities.index') }}">⚡ Utilities</a>
          <a class="hover:text-blue-600" href="/horizon" target="_blank">📊 Horizon</a>
        @endif
        <form method="post" action="{{ route('logout') }}">
          @csrf
          <button class="text-red-600 hover:underline">Logout</button>
        </form>
      </div>
    </div>
  </nav>
  <main class="w-full px-4 sm:px-6 py-4">
    <!-- Toast container fixed -->
    <div id="handoff-toast-container" class="fixed top-4 right-4 z-50 space-y-3" aria-live="polite" aria-atomic="true"></div>
    @if(session('ok'))
      <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('ok') }}</div>
    @endif
    @if($errors->any())
      <div class="mb-4 p-3 bg-rose-100 text-rose-800 rounded">
        <ul class="list-disc list-inside">
          @foreach($errors->all() as $e)
            <li>{{ $e }}</li>
          @endforeach
        </ul>
      </div>
    @endif
    @yield('content')
  </main>
  
  <!-- Laravel Echo Initialization -->
  <script>
    window.Pusher = Pusher;
    
    // Configure Pusher directly for Reverb
    const wsHost = window.location.hostname === 'chatbotplatform.test' ? 'chatbotplatform.test' : 'localhost';
    const pusher = new Pusher('jhvdpovyh6wrarhlucxh', {
        wsHost: wsHost,
        wsPort: 8080,
        enabledTransports: ['ws'],
        forceTLS: false,
        disableStats: true,
        cluster: 'mt1'
    });
    
    // Configure Echo with the manually configured Pusher instance
    window.Echo = new Echo({
        broadcaster: 'pusher',
        client: pusher,
        auth: {
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Authorization': 'Bearer dummy-token'
          }
        }
    });
    
    console.log('📡 Laravel Echo initialized for Admin Console');
  </script>

  <script>
    (function() {
      // Notification API permission
      if ("Notification" in window && Notification.permission === "default") {
        Notification.requestPermission().catch(() => {});
      }

      const container = document.getElementById('handoff-toast-container');
      const storageKey = 'operator_console_last_handoff_seen_id';
      let lastId = null;
      try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored) {
          const parsed = parseInt(stored, 10);
          if (!Number.isNaN(parsed) && parsed > 0) lastId = parsed;
        }
      } catch (e) { /* no-op */ }

      function createToast(data) {
        // Remove existing toasts to keep single persistent toast
        container.innerHTML = '';

        const toast = document.createElement('div');
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.className = 'w-96 max-w-full bg-white border border-orange-300 rounded-lg shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500';

        const priorityColors = {
          urgent: 'text-red-700 bg-red-50 border-red-200',
          high: 'text-yellow-700 bg-yellow-50 border-yellow-200',
          normal: 'text-green-700 bg-green-50 border-green-200',
          low: 'text-blue-700 bg-blue-50 border-blue-200'
        };

        const header = document.createElement('div');
        header.className = `px-4 py-3 border-b ${priorityColors[data.handoff.priority] || 'text-orange-700 bg-orange-50 border-orange-200'}`;
        header.innerHTML = `<div class="flex items-start">
          <div class="text-xl mr-2">🤝</div>
          <div class="flex-1">
            <p class="text-sm font-semibold">Richiesta operatore${data.handoff.tenant?.name ? ' • ' + data.handoff.tenant.name : ''}</p>
            <p class="text-xs opacity-80">Priorità: ${data.handoff.priority || 'normal'}</p>
          </div>
        </div>`;

        const body = document.createElement('div');
        body.className = 'px-4 py-3';
        body.innerHTML = `<p class="text-sm text-gray-800">${(data.handoff.reason || 'Utente ha richiesto assistenza')}</p>`;

        const actions = document.createElement('div');
        actions.className = 'px-4 py-3 bg-gray-50 border-t flex gap-2';

        const openBtn = document.createElement('button');
        openBtn.type = 'button';
        openBtn.className = 'flex-1 inline-flex justify-center items-center px-3 py-2 text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500';
        openBtn.setAttribute('aria-label', 'Apri conversazione');
        openBtn.textContent = 'Apri conversazione';
        openBtn.addEventListener('click', function() {
          try { sessionStorage.setItem(storageKey, String(data.handoff.id)); } catch(e) {}
          toast.remove();
          if (data.handoff?.session?.id) {
            window.location.href = `/admin/operator-console/conversations/${data.handoff.session.id}`;
          }
        });

        const ignoreBtn = document.createElement('button');
        ignoreBtn.type = 'button';
        ignoreBtn.className = 'inline-flex justify-center items-center px-3 py-2 text-sm font-medium rounded-md text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-400';
        ignoreBtn.setAttribute('aria-label', 'Ignora notifica');
        ignoreBtn.textContent = 'Ignora';
        ignoreBtn.addEventListener('click', function() {
          toast.remove();
        });

        actions.appendChild(openBtn);
        actions.appendChild(ignoreBtn);

        toast.appendChild(header);
        toast.appendChild(body);
        toast.appendChild(actions);
        container.appendChild(toast);

        // Desktop notification
        if ("Notification" in window && Notification.permission === "granted") {
          try {
            const n = new Notification('Richiesta operatore', {
              body: `${data.handoff.tenant?.name ? data.handoff.tenant.name + ' • ' : ''}${data.handoff.reason || ''}`.trim(),
              tag: `handoff-${data.handoff.id}`,
            });
            n.onclick = () => {
              window.focus();
              try { sessionStorage.setItem(storageKey, String(data.handoff.id)); } catch(e) {}
              if (data.handoff?.session?.id) {
                window.location.href = `/admin/operator-console/conversations/${data.handoff.session.id}`;
              }
            };
          } catch (e) { /* no-op */ }
        }
      }

      async function poll() {
        try {
          console.log('🔄 [Polling] Checking for new handoffs...');
          const url = new URL(window.location.origin + '/admin/operator-console/handoffs/poll');
          if (lastId) {
            url.searchParams.set('since_id', lastId);
            console.log('   Since ID:', lastId);
          }
          const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
          console.log('   Response status:', res.status, res.ok ? '✅' : '❌');
          if (!res.ok) {
            console.warn('   ⚠️  Polling failed - status not OK');
            return;
          }
          const data = await res.json();
          console.log('   Response data:', data);
          if (data && data.new && data.handoff) {
            console.log('   🔔 NEW HANDOFF DETECTED!', data.handoff);
            lastId = data.handoff.id;
            try { sessionStorage.setItem(storageKey, String(lastId)); } catch(e) {}
            createToast(data);
          } else {
            console.log('   ✅ No new handoffs (data.new =', data?.new, ')');
          }
        } catch (e) {
          console.error('❌ [Polling] Error:', e);
        }
      }

      // Start polling (5s) as fallback
      console.log('⏰ [Polling] Started - checking every 5 seconds');
      setInterval(poll, 5000);
      // Initial immediate check
      console.log('🚀 [Polling] Running initial check...');
      poll();
      
      // ✅ REAL-TIME: Listen for handoff events via WebSocket (Echo)
      @if(auth()->check() && auth()->user()->isOperator())
        console.log('🎧 Setting up Echo listener for operator...');
        
        // Get accessible tenant IDs for this operator
        const accessibleTenantIds = @json(auth()->user()->tenants()->pluck('tenants.id'));
        console.log('📋 Operator has access to tenants:', accessibleTenantIds);
        
        // ✅ FIX: Create tenant name mapping server-side (specify table prefix for ambiguous columns)
        const tenantMap = @json(auth()->user()->tenants()->pluck('tenants.name', 'tenants.id'));
        console.log('📋 Tenant name mapping:', tenantMap);
        
        // Listen on each tenant's operator channel
        accessibleTenantIds.forEach(tenantId => {
          const channelName = `tenant.${tenantId}.operators`;
          console.log(`🎧 Subscribing to private channel: ${channelName}`);
          
          window.Echo.private(channelName)
            .listen('.handoff.requested', (event) => {
              console.log('🔔 Handoff event received via WebSocket!', event);
              
              // Only show toast if handoff is newer than last seen
              if (!lastId || event.handoff_request.id > lastId) {
                lastId = event.handoff_request.id;
                try { sessionStorage.setItem(storageKey, String(lastId)); } catch(e) {}
                
                // Transform event data to match toast format
                const toastData = {
                  new: true,
                  handoff: {
                    id: event.handoff_request.id,
                    priority: event.handoff_request.priority,
                    reason: event.handoff_request.reason,
                    tenant: {
                      id: event.tenant_id,
                      name: tenantMap[event.tenant_id] || 'Tenant'  // ✅ FIX: Use server-side mapping
                    },
                    session: {
                      id: event.session.session_id
                    }
                  }
                };
                
                createToast(toastData);
                
                // Play notification sound
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+Tt4m8gBSyCzfPTgjMGHm7A7+OZSA0PU6nk682SMRQMQJX07/yHLgQlg83z14s0Bh1uwPDbmUoPEFSq5fnNlDAUDEGW9vGAPgUlg87z1oo0Bh5vwPDbmEsPEVWr5/rOlTAVDUKX9/KAPgYnhM/01ow1Bx9wwfHcmkwQElat6PvPljEVDkOY+POBPwYohNH01o01ByBxwvHdnE0RElat6fzQlzEWDkSZ+fSCQAcpiNL12I42CCFyw/LenmESDlev6v3RmDIXD0Wa+/aEQggriNP12Y82CSJzxPPfoWITD1ew6/7SmTIYEEeb/PeGQwktiNT22o43CSN0xfThomMUEFix7P/TmjMZEUif/fmHRAouidX33I84CiR1xvijpGUVEVmy7MDUmzQZEkug/vqJRgsvidb43Y86CiV2x/mlpWYWEluz7cHWnDUaFEyh//yKRwwwjNj63pA7CyZ4yPqmpmgWE1y07MHXnTYbFU2jAf6LSA0xjdr73pE8DCd5yfunp2kXFF207cLYnjgcFk6kAv+NSQ4yjNv83pI9DChxyv2opGsYFV217sPZnzgdGE+lA/+OSg8zjdz93pM+DSl6y/6qpWwZFl+27cTaoTkdGVClBQCPSxA0jtz+35RADip7zf+rpW0aGF+37cXbojkeGlGmBgGQTBE1kN3/4JVBDit8zf+spW4bGWG48cbdozofG1KnBwGRTRI2kd7+4ZVCDyx9zv+tp28cGmK58cfdozofHFOpCAGSTRM3kt//4pdDDy1+z/+wp3AdG2O79cjfpTwgHlSqCAKTThQ4k+D/45dEEC5/0P+xqHEeHGS89cnhpj0hH1WrCQOUTxU6lOIB5JhFES+A0P+yqXEfHWW99srjpz4iIFWsCgSVURY7leIB5ZlGEjCA0f+zq3IgHma+98vkqD8jIVatDAaWUhc8luQC5ppHEzGB0v+1rHMhH2e/+MzlqUAkIleuDQeXUxg9mOUD55pIFDKC0/+2rXQjIGjA+c3nqkElI1ivDgiYVRk+meYE6JtJFTOD1P+3r3UkIWnB+s7oq0ImJFqwDwmZVho/muYE6ZxKFjSE1f+4sHYlImnC+8/prEMnJVuxEAqaVxpAm+gF6p1LFzaF1/+5sXcnI2rD/M/qrUQoJly0EQubWRtBnOkG651MFzeG2P+6s3kpJGvE/dDrrkcpKF21EgycWxxCneoG7J5NGDSH2f+7tHorJWzF/dHtrkgtKV62Ew2dXB5DoewH7Z9OGTmI2v+8tnwsJ23G/tLvr0ovKmC4FA6eXR9EpO0I7qBPGjqJ3P++uH8uKG7H/9PwsEwyK2G5FRCfXiFGpu4J8KFQGzuK3f+/un8vKG/I/9PxsU0zLGK6FRGgXyJHpu4K8aJRHDyL3v/Au4EwKXHJ/9TyslA1LWO7FhKhYCNIp+8L8qNSHTyM3/+/vIIxKnLK/9X0s1I2L2S8FxOiYSRJqPEM86NTHj2N4P/AvYQyK3LK/9b1tFM4MGa+FxSjYiVKqfEM9KRUHz6O4f/BvoU0LHPM/9f2tVQ5MWe/GBWkYyZLqvIN9aVVID+P4f/CvoY1LHPM/9j3tlU6M2jAGRalYydMq/IQ9aZWIUCQ4v/Ev4g2LHPM/9n4t1Y7M2nBGhelZChNrPMR9qdXIUGR4//FwIk3LXbO/9r5uFg8NGrCGxinZSlOrvMR96dYIkKS5P/GwYo4Lnf0//v6uVk9NWvDHBmoZipPr/QS+KlZI0OT5f/HwYs5Lnf0/wAB');
                audio.volume = 0.5;
                audio.play().catch(() => {});
              }
            })
            .error((error) => {
              console.error(`❌ Error subscribing to ${channelName}:`, error);
            });
        });
        
        console.log('✅ Echo listeners setup completed');
      @else
        console.log('⚠️  Current user is not an operator, skipping Echo listeners');
      @endif
    })();
  </script>

  <!-- JavaScript Scripts -->
  @stack('scripts')
</body>
</html>

