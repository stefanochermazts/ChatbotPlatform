@extends('customer.layout')

@section('title', 'Dashboard - ' . $tenant->name)

@section('content')
<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Dashboard - {{ $tenant->name }}</h1>
  <p class="text-gray-600">Benvenuto nella dashboard del tuo chatbot</p>
</div>

<!-- Statistiche rapide -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
  <div class="bg-white p-6 rounded-lg shadow border">
    <div class="flex items-center">
      <div class="p-2 bg-blue-100 rounded-lg">
        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
        </svg>
      </div>
      <div class="ml-4">
        <p class="text-sm font-medium text-gray-600">Knowledge Bases</p>
        <p class="text-2xl font-semibold text-gray-900">{{ $stats['knowledge_bases'] }}</p>
      </div>
    </div>
  </div>

  <div class="bg-white p-6 rounded-lg shadow border">
    <div class="flex items-center">
      <div class="p-2 bg-green-100 rounded-lg">
        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
      </div>
      <div class="ml-4">
        <p class="text-sm font-medium text-gray-600">Documenti</p>
        <p class="text-2xl font-semibold text-gray-900">{{ $stats['documents'] }}</p>
      </div>
    </div>
  </div>

  <div class="bg-white p-6 rounded-lg shadow border">
    <div class="flex items-center">
      <div class="p-2 bg-purple-100 rounded-lg">
        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v6a2 2 0 002 2h6a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
        </svg>
      </div>
      <div class="ml-4">
        <p class="text-sm font-medium text-gray-600">Form</p>
        <p class="text-2xl font-semibold text-gray-900">{{ $stats['forms'] }}</p>
      </div>
    </div>
  </div>

  <div class="bg-white p-6 rounded-lg shadow border">
    <div class="flex items-center">
      <div class="p-2 bg-yellow-100 rounded-lg">
        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
        </svg>
      </div>
      <div class="ml-4">
        <p class="text-sm font-medium text-gray-600">Invii Form</p>
        <p class="text-2xl font-semibold text-gray-900">{{ $stats['form_submissions'] }}</p>
      </div>
    </div>
  </div>
</div>

<!-- Sezioni principali -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <!-- Knowledge Bases -->
  <div class="bg-white rounded-lg shadow border">
    <div class="px-6 py-4 border-b border-gray-200">
      <h3 class="text-lg font-medium text-gray-900">Knowledge Bases</h3>
    </div>
    <div class="p-6">
      @if($tenant->knowledgeBases->count() > 0)
        <div class="space-y-3">
          @foreach($tenant->knowledgeBases as $kb)
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
              <div>
                <h4 class="font-medium text-gray-900">{{ $kb->name }}</h4>
                <p class="text-sm text-gray-600">{{ $kb->description ?? 'Nessuna descrizione' }}</p>
              </div>
              <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                {{ $kb->documents()->count() }} docs
              </span>
            </div>
          @endforeach
        </div>
      @else
        <p class="text-gray-500 text-center py-4">Nessuna knowledge base configurata</p>
      @endif
    </div>
  </div>

  <!-- Widget Status -->
  <div class="bg-white rounded-lg shadow border">
    <div class="px-6 py-4 border-b border-gray-200">
      <h3 class="text-lg font-medium text-gray-900">Widget Chatbot</h3>
    </div>
    <div class="p-6">
      @if($tenant->widgetConfig)
        <div class="space-y-4">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-gray-600">Stato:</span>
            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
              {{ $tenant->widgetConfig->is_enabled ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
              {{ $tenant->widgetConfig->is_enabled ? 'Attivo' : 'Disattivo' }}
            </span>
          </div>
          
          @if($tenant->widgetConfig->is_enabled)
            <div class="mt-4">
              <a href="{{ route('widget.preview', $tenant->id) }}" 
                 target="_blank"
                 class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                🔗 Anteprima Widget
              </a>
            </div>
          @endif
        </div>
      @else
        <p class="text-gray-500 text-center py-4">Widget non configurato</p>
      @endif
    </div>
  </div>

  <!-- Form recenti -->
  <div class="bg-white rounded-lg shadow border">
    <div class="px-6 py-4 border-b border-gray-200">
      <h3 class="text-lg font-medium text-gray-900">Form Configurati</h3>
    </div>
    <div class="p-6">
      @if($tenant->forms->count() > 0)
        <div class="space-y-3">
          @foreach($tenant->forms->take(3) as $form)
            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
              <div>
                <h4 class="font-medium text-gray-900">{{ $form->name }}</h4>
                <p class="text-sm text-gray-600">{{ $form->description ?? 'Nessuna descrizione' }}</p>
              </div>
              <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                {{ $form->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                {{ $form->is_active ? 'Attivo' : 'Inattivo' }}
              </span>
            </div>
          @endforeach
        </div>
        
        @if($tenant->forms->count() > 3)
          <div class="mt-4 text-center">
            <a href="{{ route('admin.forms.index', ['tenant_id' => $tenant->id]) }}" class="text-blue-600 hover:text-blue-800 text-sm">
              Vedi tutti i {{ $tenant->forms->count() }} form →
            </a>
          </div>
        @endif
      @else
        <p class="text-gray-500 text-center py-4">Nessun form configurato</p>
      @endif
    </div>
  </div>

  <!-- Azioni rapide -->
  <div class="bg-white rounded-lg shadow border">
    <div class="px-6 py-4 border-b border-gray-200">
      <h3 class="text-lg font-medium text-gray-900">Azioni Rapide</h3>
    </div>
    <div class="p-6">
      <div class="grid grid-cols-1 gap-3">
        <a href="{{ route('admin.documents.index', $tenant) }}" 
           class="flex items-center p-3 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Gestisci Documenti
        </a>
        
        <a href="{{ route('admin.widget-config.show', $tenant) }}" 
           class="flex items-center p-3 bg-green-50 text-green-700 rounded-lg hover:bg-green-100">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.5 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
          </svg>
          Configurazione Widget
        </a>
        
        <a href="{{ route('admin.whatsapp-config.show', $tenant) }}" 
           class="flex items-center p-3 bg-emerald-50 text-emerald-700 rounded-lg hover:bg-emerald-100">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
          </svg>
          Configurazione WhatsApp
        </a>
        
        <a href="{{ route('admin.forms.index', ['tenant_id' => $tenant->id]) }}" 
           class="flex items-center p-3 bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v6a2 2 0 002 2h6a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          Gestisci Form
        </a>
        
        <a href="{{ route('admin.rag.index') }}" 
           class="flex items-center p-3 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100">
          <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
          </svg>
          Testa RAG
        </a>
      </div>
    </div>
  </div>
</div>
@endsection
