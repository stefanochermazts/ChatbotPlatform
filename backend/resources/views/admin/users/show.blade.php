@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <h1 class="text-2xl font-bold">Dettagli Utente</h1>
  <div class="flex space-x-2">
    <a href="{{ route('admin.users.edit', $user) }}" 
       class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
      Modifica
    </a>
    <a href="{{ route('admin.users.index') }}" 
       class="text-gray-600 hover:text-gray-800">
      ← Torna alla lista
    </a>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <!-- Informazioni Base -->
  <div class="lg:col-span-2">
    <div class="bg-white rounded-lg shadow p-6 mb-6">
      <h3 class="text-lg font-semibold mb-4">Informazioni Base</h3>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700">Nome</label>
          <p class="mt-1 text-sm text-gray-900">{{ $user->name }}</p>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700">Email</label>
          <p class="mt-1 text-sm text-gray-900">{{ $user->email }}</p>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700">Stato</label>
          <div class="mt-1">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
              {{ $user->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
              {{ $user->is_active ? 'Attivo' : 'Inattivo' }}
            </span>
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700">Email Verificata</label>
          <div class="mt-1">
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
              {{ $user->email_verified_at ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
              {{ $user->email_verified_at ? 'Sì' : 'No' }}
            </span>
            @if($user->email_verified_at)
              <p class="mt-1 text-xs text-gray-500">
                {{ $user->email_verified_at->format('d/m/Y H:i') }}
              </p>
            @endif
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700">Ultimo Accesso</label>
          <p class="mt-1 text-sm text-gray-900">
            {{ $user->last_login_at ? $user->last_login_at->format('d/m/Y H:i') : 'Mai' }}
          </p>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-gray-700">Registrato</label>
          <p class="mt-1 text-sm text-gray-900">
            {{ $user->created_at->format('d/m/Y H:i') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Associazioni Tenant -->
    <div class="bg-white rounded-lg shadow p-6">
      <h3 class="text-lg font-semibold mb-4">Tenant Associati</h3>
      
      @if($user->tenants->count() > 0)
        <div class="space-y-4">
          @foreach($user->tenants as $tenant)
            <div class="border border-gray-200 rounded-lg p-4">
              <div class="flex justify-between items-start">
                <div>
                  <h4 class="font-medium text-gray-900">{{ $tenant->name }}</h4>
                  @if($tenant->domain)
                    <p class="text-sm text-gray-500">{{ $tenant->domain }}</p>
                  @endif
                </div>
                <div>
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                    {{ $tenant->pivot->role === 'admin' ? 'bg-red-100 text-red-800' : '' }}
                    {{ $tenant->pivot->role === 'customer' ? 'bg-blue-100 text-blue-800' : '' }}
                    {{ $tenant->pivot->role === 'agent' ? 'bg-green-100 text-green-800' : '' }}">
                    {{ App\Models\User::ROLES[$tenant->pivot->role] ?? $tenant->pivot->role }}
                  </span>
                </div>
              </div>
              
              <div class="mt-2 text-xs text-gray-500">
                Associato dal {{ Carbon\Carbon::parse($tenant->pivot->created_at)->format('d/m/Y') }}
              </div>
            </div>
          @endforeach
        </div>
      @else
        <p class="text-gray-500 italic">Nessun tenant associato</p>
      @endif
    </div>
  </div>

  <!-- Azioni Rapide -->
  <div class="space-y-6">
    <!-- Stato Account -->
    <div class="bg-white rounded-lg shadow p-6">
      <h3 class="text-lg font-semibold mb-4">Azioni Account</h3>
      
      <div class="space-y-3">
        @if(!$user->email_verified_at)
          <form method="POST" action="{{ route('admin.users.resend-invitation', $user) }}">
            @csrf
            <button type="submit" 
                    class="w-full bg-yellow-600 text-white px-4 py-2 rounded hover:bg-yellow-700 text-sm">
              Reinvia Invito Email
            </button>
          </form>
        @endif

        <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}">
          @csrf
          @method('PATCH')
          <button type="submit" 
                  class="w-full {{ $user->is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }} text-white px-4 py-2 rounded text-sm">
            {{ $user->is_active ? 'Disattiva Utente' : 'Attiva Utente' }}
          </button>
        </form>

        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
          @csrf
          <button type="submit" 
                  class="w-full bg-orange-600 text-white px-4 py-2 rounded hover:bg-orange-700 text-sm"
                  onclick="return confirm('Sei sicuro di voler resettare la password di questo utente?')">
            Reset Password
          </button>
        </form>
      </div>
    </div>

    <!-- Statistiche -->
    <div class="bg-white rounded-lg shadow p-6">
      <h3 class="text-lg font-semibold mb-4">Statistiche</h3>
      
      <div class="space-y-3">
        <div class="flex justify-between">
          <span class="text-sm text-gray-600">Tenant Associati:</span>
          <span class="text-sm font-medium">{{ $user->tenants->count() }}</span>
        </div>
        
        <div class="flex justify-between">
          <span class="text-sm text-gray-600">Ruolo Admin:</span>
          <span class="text-sm font-medium">
            {{ $user->isAdmin() ? 'Sì' : 'No' }}
          </span>
        </div>
        
        <div class="flex justify-between">
          <span class="text-sm text-gray-600">Account Attivo:</span>
          <span class="text-sm font-medium">
            {{ $user->is_active ? 'Sì' : 'No' }}
          </span>
        </div>
      </div>
    </div>

    <!-- Pericolo -->
    @can('delete', $user)
      <div class="bg-white rounded-lg shadow p-6 border-t-4 border-red-500">
        <h3 class="text-lg font-semibold mb-4 text-red-800">Zona Pericolo</h3>
        
        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
              onsubmit="return confirm('ATTENZIONE: Questa azione è irreversibile. Sei sicuro di voler eliminare questo utente?')">
          @csrf
          @method('DELETE')
          <button type="submit" 
                  class="w-full bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 text-sm">
            Elimina Utente
          </button>
        </form>
        
        <p class="mt-2 text-xs text-red-600">
          L'eliminazione rimuoverà l'utente da tutti i tenant associati.
        </p>
      </div>
    @endcan
  </div>
</div>
@endsection
