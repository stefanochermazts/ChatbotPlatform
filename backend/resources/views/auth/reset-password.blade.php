@extends('auth.layout')

@section('title', 'Reimposta Password')
@section('heading', 'Reimposta la tua password')

@section('content')
<form method="POST" action="{{ route('password.update') }}" class="space-y-6">
  @csrf

  <input type="hidden" name="token" value="{{ $token }}">
  <input type="hidden" name="email" value="{{ $email }}">

  <div>
    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
      Indirizzo Email
    </label>
    <input id="email" name="email" type="email" autocomplete="email" required 
           value="{{ $email }}" readonly
           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-50 text-gray-500">
  </div>

  <div>
    <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
      Nuova Password
    </label>
    <input id="password" name="password" type="password" autocomplete="new-password" required
           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                  @error('password') border-red-500 @enderror">
  </div>

  <div>
    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">
      Conferma Nuova Password
    </label>
    <input id="password_confirmation" name="password_confirmation" type="password" 
           autocomplete="new-password" required
           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 
                  focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
  </div>

  <div>
    <button type="submit" 
            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm 
                   text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 
                   focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
      Reimposta Password
    </button>
  </div>
</form>
@endsection

@section('footer')
<a href="{{ route('login') }}" class="font-medium text-blue-600 hover:text-blue-500">
  Torna al login
</a>
@endsection
