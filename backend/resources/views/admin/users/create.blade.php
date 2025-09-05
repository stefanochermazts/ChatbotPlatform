@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <h1 class="text-2xl font-bold">Crea Nuovo Utente</h1>
  <a href="{{ route('admin.users.index') }}" 
     class="text-gray-600 hover:text-gray-800">
    ← Torna alla lista
  </a>
</div>

<div class="bg-white rounded-lg shadow p-6">
  <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6">
    @csrf

    <!-- Informazioni Base -->
    <div class="border-b border-gray-200 pb-6">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Informazioni Base</h3>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
            Nome Completo *
          </label>
          <input type="text" id="name" name="name" value="{{ old('name') }}" required
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
          <input type="email" id="email" name="email" value="{{ old('email') }}" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                        focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
                        @error('email') border-red-500 @enderror">
          @error('email')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
          @enderror
        </div>
      </div>
    </div>

    <!-- Associazioni Tenant -->
    <div class="border-b border-gray-200 pb-6">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Associazioni Tenant</h3>
      
      <div x-data="tenantAssociations()" x-init="init()">
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
              <select :name="'roles[' + index + ']'" :id="'role_' + index" 
                      x-model="association.role" required
                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm 
                             focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Seleziona un ruolo</option>
                @foreach(App\Models\User::ROLES as $key => $label)
                  <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
              </select>
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
      function tenantAssociations() {
        return {
          associations: [],
          
          init() {
            // Inizializza con i valori old() se presenti
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
              // Default: un'associazione vuota
              this.associations.push({ tenant_id: '', role: '' });
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

    <!-- Opzioni -->
    <div>
      <h3 class="text-lg font-medium text-gray-900 mb-4">Opzioni</h3>
      
      <div class="flex items-center">
        <input type="checkbox" id="send_invitation" name="send_invitation" value="1" 
               {{ old('send_invitation') ? 'checked' : '' }}
               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
        <label for="send_invitation" class="ml-2 block text-sm text-gray-700">
          Invia email di invito all'utente
        </label>
      </div>
      <p class="mt-1 text-sm text-gray-500">
        Se selezionato, l'utente riceverà un'email con il link per verificare l'account e impostare la password.
      </p>
    </div>

    <!-- Azioni -->
    <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
      <a href="{{ route('admin.users.index') }}" 
         class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
        Annulla
      </a>
      <button type="submit" 
              class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 
                     focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
        Crea Utente
      </button>
    </div>
  </form>
</div>
@endsection
