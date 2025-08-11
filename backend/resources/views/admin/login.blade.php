<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 grid place-items-center min-h-screen">
  <form method="post" action="{{ route('admin.login.post') }}" class="bg-white p-6 rounded shadow w-full max-w-sm">
    @csrf
    <h1 class="text-xl font-semibold mb-4">Login Admin</h1>
    @if($errors->any())
      <div class="mb-3 p-2 bg-rose-100 text-rose-800 rounded">{{ $errors->first() }}</div>
    @endif
    <label class="block text-sm mb-1">Token</label>
    <input name="token" type="password" class="w-full border rounded px-3 py-2 mb-4" required />
    <button class="w-full bg-blue-600 text-white px-3 py-2 rounded">Entra</button>
  </form>
</body>
</html>

