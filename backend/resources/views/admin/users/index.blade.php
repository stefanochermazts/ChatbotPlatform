@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <h1 class="text-2xl font-bold">Gestione Utenti</h1>
  <a href="{{ route('admin.users.create') }}" 
     class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
    + Nuovo Utente
  </a>
</div>

<!-- Filtri -->
<div class="bg-white p-4 rounded-lg shadow mb-6" x-data="{ showFilters: false }">
  <div class="flex items-center justify-between">
    <h3 class="font-semibold">Filtri</h3>
    <button @click="showFilters = !showFilters" class="text-blue-600 hover:text-blue-800">
      <span x-show="!showFilters">Mostra filtri</span>
      <span x-show="showFilters">Nascondi filtri</span>
    </button>
  </div>
  
  <form method="GET" x-show="showFilters" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Cerca</label>
      <input type="text" name="search" value="{{ request('search') }}" 
             placeholder="Nome o email..."
             class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
    </div>
    
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Ruolo</label>
      <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
        <option value="">Tutti i ruoli</option>
        @foreach(App\Models\User::ROLES as $key => $label)
          <option value="{{ $key }}" {{ request('role') === $key ? 'selected' : '' }}>
            {{ $label }}
          </option>
        @endforeach
      </select>
    </div>
    
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Tenant</label>
      <select name="tenant_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
        <option value="">Tutti i tenant</option>
        @foreach($tenants as $tenant)
          <option value="{{ $tenant->id }}" {{ request('tenant_id') == $tenant->id ? 'selected' : '' }}>
            {{ $tenant->name }}
          </option>
        @endforeach
      </select>
    </div>
    
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Stato</label>
      <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
        <option value="">Tutti</option>
        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Attivi</option>
        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inattivi</option>
        <option value="verified" {{ request('status') === 'verified' ? 'selected' : '' }}>Email verificata</option>
        <option value="unverified" {{ request('status') === 'unverified' ? 'selected' : '' }}>Email non verificata</option>
      </select>
    </div>
    
    <div class="col-span-full">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 mr-2">
        Applica Filtri
      </button>
      <a href="{{ route('admin.users.index') }}" class="text-gray-600 hover:text-gray-800">
        Cancella Filtri
      </a>
    </div>
  </form>
</div>

<!-- Lista Utenti -->
<div class="bg-white rounded-lg shadow overflow-hidden">
  <table class="min-w-full divide-y divide-gray-200">
    <thead class="bg-gray-50">
      <tr>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Utente
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Tenant & Ruoli
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Stato
        </th>
        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
          Ultimo Accesso
        </th>
        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
          Azioni
        </th>
      </tr>
    </thead>
    <tbody class="bg-white divide-y divide-gray-200">
      @forelse($users as $user)
        <tr class="hover:bg-gray-50">
          <td class="px-6 py-4 whitespace-nowrap">
            <div>
              <div class="text-sm font-medium text-gray-900">
                {{ $user->name }}
              </div>
              <div class="text-sm text-gray-500">
                {{ $user->email }}
              </div>
            </div>
          </td>
          <td class="px-6 py-4">
            <div class="space-y-1">
              @forelse($user->tenants as $tenant)
                <div class="text-xs">
                  <span class="text-gray-700">{{ $tenant->name }}</span>
                  <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                    {{ $tenant->pivot->role === 'admin' ? 'bg-red-100 text-red-800' : '' }}
                    {{ $tenant->pivot->role === 'customer' ? 'bg-blue-100 text-blue-800' : '' }}
                    {{ $tenant->pivot->role === 'agent' ? 'bg-green-100 text-green-800' : '' }}">
                    {{ App\Models\User::ROLES[$tenant->pivot->role] ?? $tenant->pivot->role }}
                  </span>
                </div>
              @empty
                <span class="text-sm text-gray-400">Nessun tenant</span>
              @endforelse
            </div>
          </td>
          <td class="px-6 py-4 whitespace-nowrap">
            <div class="space-y-1">
              <div>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                  {{ $user->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                  {{ $user->is_active ? 'Attivo' : 'Inattivo' }}
                </span>
              </div>
              <div>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                  {{ $user->email_verified_at ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                  {{ $user->email_verified_at ? 'Verificata' : 'Non verificata' }}
                </span>
              </div>
            </div>
          </td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            {{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Mai' }}
          </td>
          <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
            <div class="flex justify-end space-x-2">
              <a href="{{ route('admin.users.show', $user) }}" 
                 class="text-blue-600 hover:text-blue-900">Dettagli</a>
              <a href="{{ route('admin.users.edit', $user) }}" 
                 class="text-indigo-600 hover:text-indigo-900">Modifica</a>
              
              @if(!$user->email_verified_at)
                <form method="POST" action="{{ route('admin.users.resend-invitation', $user) }}" class="inline">
                  @csrf
                  <button type="submit" class="text-yellow-600 hover:text-yellow-900">
                    Reinvia Invito
                  </button>
                </form>
              @endif

              <form method="POST" action="{{ route('admin.users.toggle-status', $user) }}" class="inline">
                @csrf
                @method('PATCH')
                <button type="submit" 
                        class="{{ $user->is_active ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900' }}">
                  {{ $user->is_active ? 'Disattiva' : 'Attiva' }}
                </button>
              </form>
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="5" class="px-6 py-12 text-center text-gray-500">
            Nessun utente trovato.
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

<!-- Paginazione -->
@if($users->hasPages())
  <div class="mt-6">
    {{ $users->appends(request()->query())->links() }}
  </div>
@endif
@endsection
