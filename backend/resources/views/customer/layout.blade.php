<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Dashboard') - ChatbotPlatform</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <nav class="bg-white border-b border-gray-200">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
      <div class="flex items-center space-x-4">
        <a href="{{ route('dashboard') }}" class="font-semibold">{{ $tenant->name ?? 'Dashboard' }}</a>
        @auth
          <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
          @if(isset($userRole))
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
              {{ $userRole === 'admin' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800' }}">
              {{ App\Models\User::ROLES[$userRole] ?? $userRole }}
            </span>
          @endif
        @endauth
      </div>
      
      <div class="flex items-center gap-4">
        @if(isset($tenant))
          <div class="flex gap-3">
            <a class="hover:text-blue-600" href="{{ route('admin.documents.index', $tenant) }}">📄 Documenti</a>
            <a class="hover:text-blue-600" href="{{ route('admin.forms.index', ['tenant_id' => $tenant->id]) }}">📝 Form</a>
            <a class="hover:text-blue-600" href="{{ route('admin.widget-config.show', $tenant) }}">🤖 Widget</a>
            <a class="hover:text-blue-600" href="{{ route('admin.whatsapp-config.show', $tenant) }}">📱 WhatsApp</a>
            <a class="hover:text-blue-600" href="{{ route('admin.rag.index') }}">🧠 RAG Tester</a>
          </div>
        @endif
        
        @auth
          @if(auth()->user()->tenants()->count() > 1)
            <div class="relative" x-data="{ open: false }">
              <button @click="open = !open" class="text-sm text-gray-600 hover:text-gray-900">
                Cambia Tenant ▼
              </button>
              <div x-show="open" @click.away="open = false" 
                   class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg z-50">
                @foreach(auth()->user()->tenants as $userTenant)
                  <a href="{{ route('tenant.dashboard', $userTenant->id) }}" 
                     class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100
                            {{ isset($tenant) && $tenant->id === $userTenant->id ? 'bg-blue-50 text-blue-700' : '' }}">
                    {{ $userTenant->name }}
                  </a>
                @endforeach
              </div>
            </div>
          @endif
        @endauth
        
        <form method="post" action="{{ route('logout') }}">
          @csrf
          <button class="text-red-600 hover:underline">Logout</button>
        </form>
      </div>
    </div>
  </nav>
  
  <main class="max-w-6xl mx-auto p-4">
    @if(session('success'))
      <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="mb-4 p-3 bg-red-100 text-red-800 rounded">{{ session('error') }}</div>
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
</body>
</html>
