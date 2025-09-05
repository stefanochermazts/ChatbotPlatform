@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <h1 class="text-2xl font-bold">Modifica Utente</h1>
  <div class="flex space-x-2">
    <a href="{{ route('admin.users.show', $user) }}" 
       class="text-gray-600 hover:text-gray-800">
      ← Torna ai dettagli
    </a>
  </div>
</div>

<div class="bg-white rounded-lg shadow p-6">
  <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-6">
    @csrf
    @method('PUT')

    <!-- Informazioni Base -->
    <div class="border-b border-gray-200 pb-6">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Informazioni Base</h3>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
            Nome Completo *
          </label>
          <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                        focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                        @error('name') border-red-500 @enderror">
          @error('name')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
          @enderror
        </div>

        <div>
          <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
            Indirizzo Email *
          </label>
          <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                        focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                        @error('email') border-red-500 @enderror">
          @error('email')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
          @enderror
        </div>

        <div class="md:col-span-2">
          <div class="flex items-center">
            <input type="checkbox" id="is_active" name="is_active" value="1" 
                   {{ old('is_active', $user->is_active) ? 'checked' : '' }}
                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
            <label for="is_active" class="ml-2 block text-sm text-gray-700">
              Utente attivo
            </label>
          </div>
          <p class="mt-1 text-sm text-gray-500">
            Gli utenti inattivi non possono accedere alla piattaforma.
          </p>
        </div>
      </div>
    </div>

    <!-- Associazioni Tenant (per admin) -->
    @can('manageTenantAssociations', $user)
      <div class="border-b border-gray-200 pb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Associazioni Tenant</h3>
        
        @if(auth()->id() === $user->id)
          <div class="mb-4 p-3 bg-yellow-100 border border-yellow-300 rounded text-sm text-yellow-800">
            <strong>Nota:</strong> Stai modificando il tuo account. Puoi cambiare le associazioni tenant ma non i tuoi ruoli per motivi di sicurezza.
          </div>
        @endif
        
        <div x-data="tenantAssociationsEdit()" x-init="init()">
          <template x-for="(association, index) in associations" :key="index">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 p-4 border border-gray-200 rounded">
              <div>
                <label :for="'tenant_' + index" class="block text-sm font-medium text-gray-700 mb-2">
                  Tenant *
                </label>
                <select :name="'tenant_ids[' + index + ']'" :id="'tenant_' + index" 
                        x-model="association.tenant_id" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                               focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                  <option value="">Seleziona un tenant</option>
                  @foreach($tenants as $tenant)
                    <option value="{{ $tenant->id }}">{{ $tenant->name }}</option>
                  @endforeach
                </select>
              </div>

              <div>
                <label :for="'role_' + index" class="block text-sm font-medium text-gray-700 mb-2">
                  Ruolo *
                </label>
                @if(auth()->id() === $user->id)
                  <!-- Ruolo non modificabile per se stessi -->
                  <input type="hidden" :name="'roles[' + index + ']'" :value="association.role">
                  <div class="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100 text-gray-600">
                    <span x-text="getRoleLabel(association.role)"></span>
                    <span class="text-xs text-gray-500 ml-2">(non modificabile)</span>
                  </div>
                @else
                  <!-- Ruolo modificabile per altri utenti -->
                  <select :name="'roles[' + index + ']'" :id="'role_' + index" 
                          x-model="association.role" required
                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                                 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Seleziona un ruolo</option>
                    @foreach(App\Models\User::ROLES as $key => $label)
                      <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                  </select>
                @endif
              </div>

              <div class="md:col-span-2 flex justify-end">
                <button type="button" 
                        @click="removeAssociation(index)"
                        x-show="associations.length > 1"
                        class="text-red-600 hover:text-red-800 text-sm">
                  Rimuovi
                </button>
              </div>
            </div>
          </template>

          <button type="button" 
                  @click="addAssociation()"
                  class="text-blue-600 hover:text-blue-800 text-sm font-medium">
            + Aggiungi Tenant
          </button>
        </div>

        @error('tenant_ids')
          <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
        @error('roles')
          <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
        @enderror
      </div>

      <script>
        function tenantAssociationsEdit() {
          return {
            associations: [],
            roles: @json(App\Models\User::ROLES),
            
            getRoleLabel(roleKey) {
              return this.roles[roleKey] || roleKey;
            },
            
            init() {
              // Inizializza con i tenant attuali dell'utente
              const currentTenants = @json($user->tenants->pluck('id'));
              const currentRoles = @json($user->tenants->pluck('pivot.role', 'id'));
              
              // Se ci sono old values (errori di validazione), usali
              const oldTenantIds = @json(old('tenant_ids', []));
              const oldRoles = @json(old('roles', []));
              
              if (oldTenantIds.length > 0) {
                for (let i = 0; i < oldTenantIds.length; i++) {
                  this.associations.push({
                    tenant_id: oldTenantIds[i] || '',
                    role: oldRoles[i] || ''
                  });
                }
              } else {
                // Carica i tenant attuali
                for (let i = 0; i < currentTenants.length; i++) {
                  this.associations.push({
                    tenant_id: currentTenants[i].toString(),
                    role: currentRoles[currentTenants[i]] || ''
                  });
                }
                
                // Se non ci sono tenant, aggiungi un'associazione vuota
                if (this.associations.length === 0) {
                  this.associations.push({ tenant_id: '', role: '' });
                }
              }
            },
            
            addAssociation() {
              this.associations.push({ tenant_id: '', role: '' });
            },
            
            removeAssociation(index) {
              if (this.associations.length > 1) {
                this.associations.splice(index, 1);
              }
            }
          }
        }
      </script>
    @else
      <!-- Mostra solo i tenant attuali per utenti non admin -->
      <div class="border-b border-gray-200 pb-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Tenant Associati</h3>
        
        <div class="space-y-3">
          @foreach($user->tenants as $tenant)
            <div class="flex justify-between items-center p-3 bg-gray-50 rounded">
              <div>
                <span class="font-medium">{{ $tenant->name }}</span>
                @if($tenant->domain)
                  <span class="text-sm text-gray-500 ml-2">{{ $tenant->domain }}</span>
                @endif
              </div>
              <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                {{ $tenant->pivot->role === 'admin' ? 'bg-red-100 text-red-800' : '' }}
                {{ $tenant->pivot->role === 'customer' ? 'bg-blue-100 text-blue-800' : '' }}
                {{ $tenant->pivot->role === 'agent' ? 'bg-green-100 text-green-800' : '' }}">
                {{ App\Models\User::ROLES[$tenant->pivot->role] ?? $tenant->pivot->role }}
              </span>
            </div>
          @endforeach
        </div>
      </div>
    @endcan

    <!-- Informazioni di Sistema -->
    <div class="bg-gray-50 p-4 rounded">
      <h4 class="font-medium text-gray-900 mb-2">Informazioni di Sistema</h4>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
        <div>
          <span class="text-gray-600">Email verificata:</span>
          <span class="ml-1 {{ $user->email_verified_at ? 'text-green-600' : 'text-yellow-600' }}">
            {{ $user->email_verified_at ? 'Sì' : 'No' }}
          </span>
        </div>
        <div>
          <span class="text-gray-600">Ultimo accesso:</span>
          <span class="ml-1">{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Mai' }}</span>
        </div>
        <div>
          <span class="text-gray-600">Registrato:</span>
          <span class="ml-1">{{ $user->created_at->diffForHumans() }}</span>
        </div>
      </div>
    </div>

    <!-- Azioni -->
    <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
      <a href="{{ route('admin.users.show', $user) }}" 
         class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
        Annulla
      </a>
      <button type="submit" 
              class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 
                     focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
        Salva Modifiche
      </button>
    </div>
  </form>
</div>
@endsection
