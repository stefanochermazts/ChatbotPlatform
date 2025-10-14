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
          const url = new URL(window.location.origin + '/admin/operator-console/handoffs/poll');
          if (lastId) url.searchParams.set('since_id', lastId);
          const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
          if (!res.ok) return;
          const data = await res.json();
          if (data && data.new && data.handoff) {
            lastId = data.handoff.id;
            try { sessionStorage.setItem(storageKey, String(lastId)); } catch(e) {}
            createToast(data);
          }
        } catch (e) { /* silent */ }
      }

      // Start polling (5s)
      setInterval(poll, 5000);
      // Initial immediate check
      poll();
    })();
  </script>

  <!-- JavaScript Scripts -->
  @stack('scripts')
</body>
</html>

