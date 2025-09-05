<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'Autenticazione') - ChatbotPlatform</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center">
  <div class="max-w-md w-full space-y-8 p-8">
    <div>
      <h1 class="text-center text-3xl font-bold text-gray-900 mb-2">ChatBot Platform</h1>
      <h2 class="text-center text-xl text-gray-600">@yield('heading')</h2>
    </div>

    <div class="bg-white p-8 rounded-lg shadow-lg border border-gray-200">
      @if(session('status'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded text-sm">
          {{ session('status') }}
        </div>
      @endif

      @if(session('success'))
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded text-sm">
          {{ session('success') }}
        </div>
      @endif

      @if($errors->any())
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded text-sm">
          <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      @yield('content')
    </div>

    <div class="text-center text-sm text-gray-600">
      @yield('footer')
    </div>
  </div>
</body>
</html>
