@extends('admin.layout')

@section('content')
<div>
        
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">
                        📋 Submission #{{ $submission->id }}
                    </h1>
                    <p class="mt-2 text-gray-600">
                        Form: <strong>{{ $submission->tenantForm->name }}</strong> | 
                        Tenant: <strong>{{ $submission->tenant->name }}</strong>
                    </p>
                </div>
                
                <div class="flex space-x-4">
                    <a href="{{ route('admin.forms.submissions.index') }}" 
                       class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        ← Torna alla Lista
                    </a>
                    
                    @if($submission->status !== 'closed')
                    <a href="{{ route('admin.forms.submissions.respond', $submission) }}" 
                       class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                        💬 Rispondi
                    </a>
                    @endif
                </div>
            </div>
        </div>

        <!-- Status Badge -->
        <div class="mb-6">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                @if($submission->status === 'pending') bg-yellow-100 text-yellow-800
                @elseif($submission->status === 'responded') bg-blue-100 text-blue-800
                @elseif($submission->status === 'closed') bg-green-100 text-green-800
                @else bg-gray-100 text-gray-800 @endif">
                @if($submission->status === 'pending') ⏳ In Attesa
                @elseif($submission->status === 'responded') 💬 Risposto
                @elseif($submission->status === 'closed') ✅ Chiuso
                @else {{ ucfirst($submission->status) }} @endif
            </span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Submission Details -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">📝 Dettagli Submission</h2>
                    
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Data Invio</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $submission->submitted_at->format('d/m/Y H:i:s') }}
                            </dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Session ID</dt>
                            <dd class="mt-1 text-sm text-gray-900 font-mono">
                                {{ $submission->session_id }}
                            </dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Trigger Type</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ ucfirst($submission->trigger_type ?? 'N/A') }}
                            </dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Trigger Value</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ $submission->trigger_value ?? 'N/A' }}
                            </dd>
                        </div>
                        
                        @if($submission->ip_address)
                        <div>
                            <dt class="text-sm font-medium text-gray-500">IP Address</dt>
                            <dd class="mt-1 text-sm text-gray-900 font-mono">
                                {{ $submission->ip_address }}
                            </dd>
                        </div>
                        @endif
                        
                        @if($submission->user_agent)
                        <div class="sm:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">User Agent</dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all">
                                {{ $submission->user_agent }}
                            </dd>
                        </div>
                        @endif
                    </dl>
                </div>

                <!-- Form Data -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">📊 Dati del Form</h2>
                    
                    @if($submission->form_data)
                        <div class="space-y-4">
                            @foreach($submission->form_data as $field => $value)
                                <div class="border-b border-gray-200 pb-2">
                                    <dt class="text-sm font-medium text-gray-500 uppercase tracking-wide">
                                        {{ str_replace('_', ' ', $field) }}
                                    </dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        @if(is_array($value))
                                            {{ implode(', ', $value) }}
                                        @else
                                            {{ $value }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-gray-500 italic">Nessun dato disponibile</p>
                    @endif
                </div>

                <!-- Chat Context -->
                @if($submission->chat_context && count($submission->chat_context) > 0)
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">💬 Contesto Chat</h2>
                    
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        @foreach($submission->chat_context as $message)
                            <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-xs lg:max-w-md px-4 py-2 rounded-lg
                                    {{ $message['role'] === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-900' }}">
                                    <div class="text-xs opacity-75 mb-1">
                                        {{ $message['role'] === 'user' ? '👤 Utente' : '🤖 Bot' }}
                                    </div>
                                    <div class="text-sm">
                                        {{ $message['content'] }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-1">
                
                <!-- Quick Actions -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">🚀 Azioni Rapide</h3>
                    
                    <div class="space-y-3">
                        @if($submission->status !== 'closed')
                        <a href="{{ route('admin.forms.submissions.respond', $submission) }}" 
                           class="w-full bg-blue-500 hover:bg-blue-700 text-white text-center py-2 px-4 rounded block">
                            💬 Rispondi
                        </a>
                        @endif
                        
                        <form method="POST" action="{{ route('admin.forms.submissions.update-status', $submission) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $submission->status === 'closed' ? 'pending' : 'closed' }}">
                            <button type="submit" 
                                    class="w-full {{ $submission->status === 'closed' ? 'bg-yellow-500 hover:bg-yellow-700' : 'bg-green-500 hover:bg-green-700' }} text-white text-center py-2 px-4 rounded">
                                {{ $submission->status === 'closed' ? '🔄 Riapri' : '✅ Chiudi' }}
                            </button>
                        </form>
                        
                        <form method="POST" action="{{ route('admin.forms.submissions.destroy', $submission) }}" 
                              onsubmit="return confirm('Sei sicuro di voler eliminare questa submission?')" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" 
                                    class="w-full bg-red-500 hover:bg-red-700 text-white text-center py-2 px-4 rounded">
                                🗑️ Elimina
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Response History -->
                @if($submission->responses && $submission->responses->count() > 0)
                <div class="bg-white shadow rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        📨 Cronologia Risposte ({{ $submission->responses->count() }})
                    </h3>
                    
                    <div class="space-y-4">
                        @foreach($submission->responses as $response)
                            <div class="border-l-4 border-blue-400 bg-blue-50 p-4 rounded">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="text-sm font-medium text-blue-900">
                                        {{ $response->adminUser->name ?? 'Admin' }}
                                    </div>
                                    <div class="text-xs text-blue-600">
                                        {{ $response->created_at->format('d/m H:i') }}
                                    </div>
                                </div>
                                
                                @if($response->email_subject)
                                <div class="text-sm font-semibold text-blue-800 mb-1">
                                    📧 {{ $response->email_subject }}
                                </div>
                                @endif
                                
                                <div class="text-sm text-blue-700">
                                    {{ $response->response_content }}
                                </div>
                                
                                <div class="flex items-center space-x-4 mt-2 text-xs text-blue-600">
                                    <span class="bg-blue-200 px-2 py-1 rounded">
                                        {{ ucfirst($response->response_type) }}
                                    </span>
                                    
                                    @if($response->closes_submission)
                                    <span class="bg-green-200 text-green-800 px-2 py-1 rounded">
                                        ✅ Chiude Ticket
                                    </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
</div>
@endsection
