<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin - ChatbotPlatform</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 text-gray-900">
  <nav class="bg-white border-b border-gray-200">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
      <a href="{{ route('admin.dashboard') }}" class="font-semibold">Admin</a>
      <div class="flex gap-4">
        <a class="hover:text-blue-600" href="{{ route('admin.tenants.index') }}">Clienti</a>
        <a class="hover:text-blue-600" href="{{ route('admin.forms.index') }}">📝 Form</a>
        <a class="hover:text-blue-600" href="{{ route('admin.widget-config.index') }}">Widget</a>
        <a class="hover:text-blue-600" href="{{ route('admin.widget-analytics.index') }}">Analytics</a>
        <a class="hover:text-blue-600" href="{{ route('admin.rag.index') }}">RAG Tester</a>
        <form method="post" action="{{ route('admin.logout') }}">
          @csrf
          <button class="text-red-600 hover:underline">Logout</button>
        </form>
      </div>
    </div>
  </nav>
  <main class="max-w-6xl mx-auto p-4">
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
</body>
</html>

