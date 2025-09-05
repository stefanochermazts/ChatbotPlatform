@extends('auth.layout')

@section('title', 'Password dimenticata')
@section('heading', 'Recupera la tua password')

@section('content')
<div class="mb-4 text-sm text-gray-600">
  Hai dimenticato la password? Nessun problema. Inserisci il tuo indirizzo email e ti invieremo un link per reimpostarla.
</div>

<form method="POST" action="{{ route('password.email') }}" class="space-y-6">
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
    <button type="submit" 
            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm 
                   text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 
                   focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
      Invia Link di Recupero
    </button>
  </div>
</form>
@endsection

@section('footer')
<a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-500">
  Torna al login
</a>
@endsection
