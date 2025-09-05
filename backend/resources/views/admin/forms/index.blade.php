@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <h1 class="text-2xl font-bold">Form Dinamici</h1>
  @if(auth()->user()->isAdmin())
    <a href="{{ route('admin.forms.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
      ➕ Nuovo Form
    </a>
  @endif
</div>

<!-- Filtri -->
<div class="bg-white rounded-lg shadow-sm border p-4 mb-6">
  <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
    @if(auth()->user()->isAdmin())
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Tenant</label>
        <select name="tenant_id" class="w-full border rounded-lg px-3 py-2">
          <option value="">Tutti i tenant</option>
          @foreach($tenants as $tenant)
            <option value="{{ $tenant->id }}" @selected(request('tenant_id') == $tenant->id)>
              {{ $tenant->name }}
            </option>
          @endforeach
        </select>
      </div>
    @endif
    
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Stato</label>
      <select name="active" class="w-full border rounded-lg px-3 py-2">
        <option value="">Tutti</option>
        <option value="1" @selected(request('active') === '1')>Attivi</option>
        <option value="0" @selected(request('active') === '0')>Disattivi</option>
      </select>
    </div>
    
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Cerca</label>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Nome o descrizione..." 
             class="w-full border rounded-lg px-3 py-2">
    </div>
    
    <div class="flex items-end">
      <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 mr-2">
        🔍 Filtra
      </button>
      <a href="{{ route('admin.forms.index') }}" class="bg-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-400">
        🔄 Reset
      </a>
    </div>
  </form>
</div>

<!-- Lista Form -->
<div class="bg-white rounded-lg shadow-sm border overflow-hidden">
  @if($forms->count() > 0)
    <div class="overflow-x-auto">
      <table class="w-full">
        <thead class="bg-gray-50 border-b">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Form</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tenant</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sottomissioni</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Campi</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Creato</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Azioni</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          @foreach($forms as $form)
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-4">
                <div>
                  <div class="text-sm font-medium text-gray-900">{{ $form->name }}</div>
                  @if($form->description)
                    <div class="text-sm text-gray-500">{{ Str::limit($form->description, 60) }}</div>
                  @endif
                </div>
              </td>
              <td class="px-6 py-4 text-sm text-gray-900">
                {{ $form->tenant->name }}
              </td>
              <td class="px-6 py-4">
                @if($form->active)
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    ✅ Attivo
                  </span>
                @else
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    ⏸️ Disattivo
                  </span>
                @endif
              </td>
              <td class="px-6 py-4 text-sm text-gray-900">
                <div class="flex items-center space-x-2">
                  @if($form->pending_count > 0)
                    <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full text-xs font-medium">
                      ⏳ {{ $form->pending_count }}
                    </span>
                  @endif
                  <span class="text-gray-500">{{ $form->total_count }} totali</span>
                </div>
              </td>
              <td class="px-6 py-4 text-sm text-gray-500">
                {{ $form->fields->count() }} campi
              </td>
              <td class="px-6 py-4 text-sm text-gray-500">
                {{ $form->created_at->format('d/m/Y H:i') }}
              </td>
              <td class="px-6 py-4 text-sm font-medium space-x-2">
                <a href="{{ route('admin.forms.show', $form) }}" 
                   class="text-blue-600 hover:text-blue-900" title="Visualizza">👁️</a>
                <a href="{{ route('admin.forms.edit', $form) }}" 
                   class="text-indigo-600 hover:text-indigo-900" title="Modifica">✏️</a>
                <a href="{{ route('admin.forms.preview', $form) }}" 
                   class="text-green-600 hover:text-green-900" title="Anteprima">🔍</a>
                
                <form method="POST" action="{{ route('admin.forms.toggle-active', $form) }}" class="inline">
                  @csrf
                  <button type="submit" 
                          class="{{ $form->active ? 'text-yellow-600 hover:text-yellow-900' : 'text-green-600 hover:text-green-900' }}" 
                          title="{{ $form->active ? 'Disattiva' : 'Attiva' }}">
                    {{ $form->active ? '⏸️' : '▶️' }}
                  </button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    
    <!-- Pagination -->
    <div class="px-6 py-4 border-t">
      {{ $forms->links() }}
    </div>
  @else
    <div class="text-center py-12">
      <div class="text-gray-500 text-lg mb-4">📝 Nessun form trovato</div>
      <p class="text-gray-400 mb-6">Inizia creando il tuo primo form dinamico.</p>
      <a href="{{ route('admin.forms.create') }}" 
         class="bg-blue-600 text-white px-6 py-3 rounded-lg hover:bg-blue-700">
        ➕ Crea Primo Form
      </a>
    </div>
  @endif
</div>

@if(session('success'))
  <div class="fixed bottom-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
    ✅ {{ session('success') }}
  </div>
@endif

@if(session('error'))
  <div class="fixed bottom-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg">
    ❌ {{ session('error') }}
  </div>
@endif
@endsection
