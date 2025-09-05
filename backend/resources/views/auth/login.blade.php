@extends('auth.layout')

@section('title', 'Accedi')
@section('heading', 'Accedi al tuo account')

@section('content')
<form method="POST" action="{{ route('login') }}" class="space-y-6">
  @csrf
  
  <div>
    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
      Indirizzo Email
    </label>
    <input id="email" name="email" type="email" autocomplete="email" required 
           value="{{ old('email') }}"
           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                  @error('email') border-red-500 @enderror">
  </div>

  <div>
    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
      Password
    </label>
    <input id="password" name="password" type="password" autocomplete="current-password" required
           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                  @error('password') border-red-500 @enderror">
  </div>

  <div class="flex items-center justify-between">
    <div class="flex items-center">
      <input id="remember" name="remember" type="checkbox" 
             class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
      <label for="remember" class="ml-2 block text-sm text-gray-700">
        Ricordami
      </label>
    </div>

    <div class="text-sm">
      <a href="{{ route('password.request') }}" class="font-medium text-blue-600 hover:text-blue-500">
        Password dimenticata?
      </a>
    </div>
  </div>

  <div>
    <button type="submit" 
            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm 
                   text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 
                   focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500
                   disabled:opacity-50 disabled:cursor-not-allowed">
      Accedi
    </button>
  </div>
</form>
@endsection

@section('footer')
<p>Non hai ricevuto l'email di invito? Contatta l'amministratore.</p>
@endsection
