@extends('auth.layout')

@section('title', 'Nessun Tenant Associato')
@section('heading', 'Accesso Negato')

@section('content')
<div class="text-center">
  <svg class="mx-auto h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z" />
  </svg>
  
  <h3 class="mt-4 text-lg font-medium text-gray-900">Nessun Tenant Associato</h3>
  
  <p class="mt-2 text-sm text-gray-600">
    Il tuo account non è associato a nessun tenant. <br>
    Contatta l'amministratore per ottenere l'accesso.
  </p>
  
  <div class="mt-6 space-y-3">
    <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-yellow-800">
            Cosa fare ora?
          </h3>
          <div class="mt-2 text-sm text-yellow-700">
            <ul class="list-disc list-inside space-y-1">
              <li>Contatta l'amministratore del sistema</li>
              <li>Verifica di aver verificato la tua email</li>
              <li>Assicurati che il tuo account sia stato attivato</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    
    <div class="flex justify-center space-x-4">
      <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="text-red-600 hover:text-red-800 text-sm">
          Esci dall'Account
        </button>
      </form>
    </div>
  </div>
</div>
@endsection

@section('footer')
<p>Hai problemi? Contatta il supporto tecnico.</p>
@endsection
