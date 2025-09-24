@extends('admin.layout')

@section('title', $title)

@section('content')
<div class="w-full py-6">
    <div class="px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">💬 Conversazione</h1>
                <p class="mt-2 text-sm text-gray-600">
                    {{ $session->user_identifier ?? 'Utente Anonimo' }} • {{ $session->tenant->name ?? 'N/A' }}
                </p>
            </div>
            <div class="mt-4 sm:mt-0 flex space-x-3">
                @if($session->status === 'active' && in_array($session->handoff_status, ['bot_only', 'handoff_requested']))
                    <!-- Take Over Button -->
                    <form method="POST" action="{{ route('admin.operator-console.conversations.takeover', $session) }}" class="inline">
                        @csrf
                        <button type="submit" 
                                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                onclick="return confirm('Vuoi prendere controllo di questa conversazione?')">
                            🎯 Prendi Controllo
                        </button>
                    </form>
                @elseif($session->assigned_operator_id === auth()->id() && $session->handoff_status === 'handoff_active')
                    <!-- Release Button -->
                    <button type="button" 
                            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
                            onclick="showReleaseModal()">
                        🏁 Rilascia
                    </button>
                @endif
                
                <!-- Admin Actions -->
                @if(auth()->user()->isAdmin())
                    <button type="button" 
                            onclick="confirmDeleteConversation()"
                            class="inline-flex items-center px-4 py-2 border border-red-300 rounded-md shadow-sm text-sm font-medium text-red-700 bg-white hover:bg-red-50">
                        🗑️ Elimina Conversazione
                    </button>
                @endif
                
                <a href="{{ route('admin.operator-console.conversations') }}" 
                   class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    ← Lista Conversazioni
                </a>
            </div>
        </div>

        <!-- Session Info Card -->
        <div class="mt-6 bg-white shadow rounded-lg">
            <div class="px-4 py-5 sm:p-6">
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                    <!-- Status -->
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Status</dt>
                        <dd class="mt-1 flex items-center">
                            @switch($session->status)
                                @case('active')
                                    <div class="flex-shrink-0 w-3 h-3 bg-green-400 rounded-full mr-2"></div>
                                    <span class="text-sm font-medium text-green-800">🤖 Bot Attivo</span>
                                    @break
                                @case('assigned')
                                    <div class="flex-shrink-0 w-3 h-3 bg-blue-400 rounded-full mr-2"></div>
                                    <span class="text-sm font-medium text-blue-800">
                                        @if($session->handoff_status === 'handoff_active')
                                            👨‍💼 Operatore Attivo
                                        @else
                                            👨‍💼 Operatore Assegnato
                                        @endif
                                    </span>
                                    @break
                                @case('resolved')
                                    <div class="flex-shrink-0 w-3 h-3 bg-gray-400 rounded-full mr-2"></div>
                                    <span class="text-sm font-medium text-gray-800">✅ Risolto</span>
                                    @break
                            @endswitch
                        </dd>
                    </div>

                    <!-- Operatore -->
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Operatore Assegnato</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            @if($session->assignedOperator)
                                👨‍💼 {{ $session->assignedOperator->name }}
                                @if($session->assignedOperator->id === auth()->id())
                                    <span class="text-green-600">(Tu)</span>
                                @endif
                            @else
                                <span class="text-gray-500">Nessuno</span>
                            @endif
                        </dd>
                    </div>

                    <!-- Timing -->
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Tempistiche</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            <div>Iniziata: {{ $session->started_at->format('d/m/Y H:i') }}</div>
                            <div>Ultima attività: {{ $session->last_activity_at->diffForHumans() }}</div>
                            @if($session->closed_at)
                                <div>Chiusa: {{ $session->closed_at->format('d/m/Y H:i') }}</div>
                            @endif
                        </dd>
                    </div>
                </div>

                <!-- Session Details -->
                <div class="mt-6 border-t border-gray-200 pt-6">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">ID Sessione</dt>
                            <dd class="mt-1 text-sm text-gray-900 font-mono">{{ $session->session_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Canale</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ ucfirst($session->channel) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Messaggi Totali</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $session->messages->count() }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Handoff Requests</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $session->handoffRequests->count() }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Messages Timeline -->
        <div class="mt-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">📝 Timeline Messaggi</h2>
            
            <div id="messages-container">
            @if($session->messages->count() > 0)
                <div class="flow-root">
                    <ul role="list" class="-mb-8" id="messages-list">
                        @foreach($session->messages as $index => $message)
                        <li>
                            <div class="relative pb-8">
                                @if(!$loop->last)
                                    <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                @endif
                                
                                <div class="relative flex space-x-3">
                                    <!-- Avatar -->
                                    <div>
                                        @switch($message->sender_type)
                                            @case('user')
                                                <span class="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center ring-8 ring-white">
                                                    <span class="text-white text-sm font-medium">👤</span>
                                                </span>
                                                @break
                                            @case('system')
                                                <span class="h-8 w-8 rounded-full bg-gray-500 flex items-center justify-center ring-8 ring-white">
                                                    <span class="text-white text-sm font-medium">🤖</span>
                                                </span>
                                                @break
                                            @case('operator')
                                                <span class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white">
                                                    <span class="text-white text-sm font-medium">👨‍💼</span>
                                                </span>
                                                @break
                                        @endswitch
                                    </div>
                                    
                                    <!-- Message Content -->
                                    <div class="min-w-0 flex-1">
                                        <div>
                                            <div class="text-sm">
                                                <span class="font-medium text-gray-900">
                                                    @switch($message->sender_type)
                                                        @case('user')
                                                            {{ $message->sender_name ?? 'Utente' }}
                                                            @break
                                                        @case('system')
                                                            Sistema/Bot
                                                            @break
                                                        @case('operator')
                                                            {{ $message->sender_name ?? 'Operatore' }}
                                                            @break
                                                    @endswitch
                                                </span>
                                            </div>
                                            <p class="mt-0.5 text-sm text-gray-500">
                                                {{ $message->sent_at->format('d/m/Y H:i:s') }}
                                                @if($message->confidence !== null)
                                                    • Confidence: {{ number_format($message->confidence * 100, 1) }}%
                                                @endif
                                            </p>
                                        </div>
                                        
                                        <!-- Message Body -->
                                        <div class="mt-2 text-sm text-gray-700">
                                            <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                                                @if($message->content_type === 'markdown')
                                                    {!! \Illuminate\Support\Str::markdown($message->content) !!}
                                                @else
                                                    <p class="whitespace-pre-wrap">{{ $message->content }}</p>
                                                @endif
                                                
                                                @if($message->citations && count($message->citations) > 0)
                                                    <div class="mt-3 pt-3 border-t border-gray-100">
                                                        <h4 class="text-xs font-medium text-gray-500 uppercase tracking-wider">Citazioni</h4>
                                                        <ul class="mt-2 space-y-1">
                                                            @foreach($message->citations as $citation)
                                                                <li class="text-xs text-gray-600">
                                                                    📄 {{ $citation['title'] ?? 'Documento' }}
                                                                    @if(isset($citation['score']))
                                                                        (Score: {{ number_format($citation['score'] * 100, 1) }}%)
                                                                    @endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <!-- Message Actions -->
                                        @if($message->is_helpful !== null)
                                            <div class="mt-2">
                                                @if($message->is_helpful)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                        👍 Utile
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                                                        👎 Non utile
                                                    </span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="text-center py-12 bg-gray-50 rounded-lg" id="no-messages">
                    <span class="text-4xl block mb-2">💬</span>
                    <h3 class="text-lg font-medium text-gray-900">Nessun messaggio</h3>
                    <p class="text-sm text-gray-500">La conversazione non ha ancora messaggi.</p>
                </div>
            @endif
            </div>
        </div>

        <!-- Operator Chat Interface (solo se assegnato all'operatore corrente) -->
        @if($session->assigned_operator_id === auth()->id() && $session->handoff_status === 'handoff_active')
        <div class="mt-8">
            <div class="bg-white shadow rounded-lg">
                <div class="px-4 py-5 sm:p-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">💬 Chat con l'Utente</h2>
                    
                    <!-- Chat Input Form -->
                    <form id="operatorMessageForm" class="space-y-4">
                        @csrf
                        <div>
                            <label for="message_content" class="block text-sm font-medium text-gray-700">Messaggio</label>
                            <div class="mt-1">
                                <textarea id="message_content" name="content" rows="3" 
                                          class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                                          placeholder="Scrivi la tua risposta all'utente..."
                                          maxlength="2000"></textarea>
                            </div>
                            <div class="mt-1 text-xs text-gray-500">
                                <span id="char-count">0</span> / 2000 caratteri
                            </div>
                        </div>
                        
                        <!-- Quick Responses -->
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="quick-response inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200" 
                                    data-text="Ciao! Sono qui per aiutarti. Come posso assisterti?">
                                👋 Saluto
                            </button>
                            <button type="button" class="quick-response inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 hover:bg-green-200" 
                                    data-text="Ho trovato le informazioni che cercavi. Ecco i dettagli:">
                                ✅ Risposta trovata
                            </button>
                            <button type="button" class="quick-response inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 hover:bg-yellow-200" 
                                    data-text="Sto verificando queste informazioni per te. Ti rispondo a breve.">
                                🔍 Verifico
                            </button>
                            <button type="button" class="quick-response inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 hover:bg-purple-200" 
                                    data-text="Grazie per aver contattato il servizio. È stato un piacere aiutarti!">
                                🙏 Ringraziamento
                            </button>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <div class="flex items-center space-x-3">
                                <div class="flex items-center">
                                    <input id="content_type_text" name="content_type" type="radio" value="text" checked 
                                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                                    <label for="content_type_text" class="ml-2 block text-sm text-gray-900">Testo</label>
                                </div>
                                <div class="flex items-center">
                                    <input id="content_type_markdown" name="content_type" type="radio" value="markdown" 
                                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                                    <label for="content_type_markdown" class="ml-2 block text-sm text-gray-900">Markdown</label>
                                </div>
                            </div>
                            
                            <div class="flex space-x-3">
                                <button type="button" id="clearMessage" 
                                        class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    🗑️ Pulisci
                                </button>
                                <button type="submit" id="sendMessage" 
                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                        disabled>
                                    📤 Invia Messaggio
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Status Indicator -->
                    <div id="sendStatus" class="mt-4 hidden">
                        <div class="rounded-md p-4">
                            <div class="flex">
                                <div class="ml-3">
                                    <div id="statusMessage" class="text-sm font-medium"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Handoff Requests (se presenti) -->
        @if($session->handoffRequests->count() > 0)
        <div class="mt-8">
            <h2 class="text-lg font-medium text-gray-900 mb-4">🤝 Richieste Handoff</h2>
            <div class="bg-white shadow overflow-hidden sm:rounded-md">
                <ul role="list" class="divide-y divide-gray-200">
                    @foreach($session->handoffRequests as $handoff)
                    <li class="px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    @switch($handoff->priority)
                                        @case('urgent')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                🔴 Urgente
                                            </span>
                                            @break
                                        @case('high')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                🟡 Alta
                                            </span>
                                            @break
                                        @case('normal')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                🟢 Normale
                                            </span>
                                            @break
                                        @case('low')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                🔵 Bassa
                                            </span>
                                            @break
                                    @endswitch
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ ucfirst($handoff->trigger_type) }} • {{ ucfirst($handoff->status) }}
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        {{ $handoff->reason }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ $handoff->requested_at->diffForHumans() }}
                            </div>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif
    </div>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 1000;">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">🏁 Rilascia Conversazione</h3>
            <form method="POST" action="{{ route('admin.operator-console.conversations.release', $session) }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Azione</label>
                    <div class="space-y-2">
                        <label class="flex items-center">
                            <input type="radio" name="transfer_back_to_bot" value="1" checked class="mr-2">
                            <span class="text-sm">🤖 Trasferisci al bot</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="transfer_back_to_bot" value="0" class="mr-2">
                            <span class="text-sm">✅ Chiudi conversazione</span>
                        </label>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="resolution_note" class="block text-sm font-medium text-gray-700 mb-2">Note di risoluzione (opzionale)</label>
                    <textarea name="resolution_note" id="resolution_note" rows="3" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="Riassunto di cosa è stato risolto..."></textarea>
                </div>
                <div class="flex space-x-3">
                    <button type="submit" class="flex-1 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                        🏁 Rilascia
                    </button>
                    <button type="button" onclick="hideReleaseModal()" class="flex-1 inline-flex justify-center py-2 px-4 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Annulla
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
console.log('💬 Chat Interface JavaScript loading...');

// Release Modal Functions
function showReleaseModal() {
    const modal = document.getElementById('releaseModal');
    if (modal) {
        modal.classList.remove('hidden');
    } else {
        console.error('Release modal not found');
    }
}

function hideReleaseModal() {
    const modal = document.getElementById('releaseModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// Delete Conversation Function
function confirmDeleteConversation() {
    if (confirm('⚠️ ATTENZIONE!\n\nSei sicuro di voler eliminare questa conversazione?\n\nQuesta azione eliminerà:\n- Tutti i messaggi\n- Le richieste handoff associate\n- La sessione completa\n\nQuesta azione NON PUÒ essere annullata!')) {
        // Create and submit delete form
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route("admin.operator-console.conversations.delete", $session) }}';
        
        // Add CSRF token
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        form.appendChild(csrfInput);
        
        // Add method override for DELETE
        const methodInput = document.createElement('input');
        methodInput.type = 'hidden';
        methodInput.name = '_method';
        methodInput.value = 'DELETE';
        form.appendChild(methodInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Chat Interface Initialization
document.addEventListener('DOMContentLoaded', function() {
    console.log('💬 DOM loaded - initializing chat interface');
    
    // Wait for elements to be fully rendered
    setTimeout(function() {
        const form = document.getElementById('operatorMessageForm');
        const textarea = document.getElementById('message_content');
        const sendButton = document.getElementById('sendMessage');
        const charCount = document.getElementById('char-count');
        const clearButton = document.getElementById('clearMessage');
        
        console.log('💬 Elements found:', {
            form: !!form,
            textarea: !!textarea,
            sendButton: !!sendButton,
            charCount: !!charCount,
            clearButton: !!clearButton
        });
        
        if (!textarea || !sendButton || !charCount) {
            console.warn('💬 Chat interface elements not found - skipping initialization');
            return;
        }
        
        // Initial setup
        sendButton.disabled = true;
        charCount.textContent = '0';
        
        // Character counter
        textarea.addEventListener('input', function() {
            const length = this.value.trim().length;
            charCount.textContent = length;
            sendButton.disabled = length === 0 || length > 2000;
            console.log('💬 Character count:', length, 'Button disabled:', sendButton.disabled);
        });
        
        // Quick responses
        const quickResponses = document.querySelectorAll('.quick-response');
        console.log('💬 Found quick responses:', quickResponses.length);
        
        quickResponses.forEach(function(button) {
            button.addEventListener('click', function() {
                const text = this.getAttribute('data-text');
                console.log('💬 Quick response clicked:', text);
                textarea.value = text;
                textarea.dispatchEvent(new Event('input'));
                textarea.focus();
            });
        });
        
        // Clear button
        if (clearButton) {
            clearButton.addEventListener('click', function() {
                textarea.value = '';
                textarea.dispatchEvent(new Event('input'));
                textarea.focus();
            });
        }
        
        // Send message function
        function sendMessage() {
            console.log('💬 sendMessage() called');
            
            const content = textarea.value.trim();
            if (!content || content.length > 2000) {
                console.log('❌ Invalid content length:', content.length);
                return;
            }

            // Disable button
            sendButton.disabled = true;
            sendButton.innerHTML = '⏳ Invio...';
            
            console.log('🚀 Sending AJAX to:', '{{ route("admin.operator-console.conversations.send-message", $session) }}');

            // Send via AJAX
            fetch('{{ route("admin.operator-console.conversations.send-message", $session) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    content: content,
                    content_type: 'text'
                })
            })
            .then(response => {
                console.log('🚀 Response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('🚀 Response data:', data);
                if (data.success) {
                    console.log('✅ Message sent successfully');
                    textarea.value = '';
                    textarea.dispatchEvent(new Event('input'));
                    
                    // Reload page to show new message
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    console.error('❌ Send failed:', data.error);
                    alert('Errore: ' + (data.error || 'Invio fallito'));
                }
            })
            .catch(error => {
                console.error('❌ Network error:', error);
                alert('Errore di connessione: ' + error.message);
            })
            .finally(() => {
                sendButton.disabled = false;
                sendButton.innerHTML = '📤 Invia Messaggio';
            });
        }
        
        // Send button click handler
        if (sendButton) {
            sendButton.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('📤 Send button clicked');
                sendMessage();
            });
        }
        
        // Form submission handler
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('📤 Form submitted');
                sendMessage();
            });
        }
        
        console.log('💬 Chat interface fully initialized');
        
    }, 100); // Wait 100ms for elements to render
});

// === Auto-refresh timeline ogni 10s ===
const REFRESH_INTERVAL_MS = 10000;
let refreshTimer = null;

function renderMessages(messages) {
    const list = document.getElementById('messages-list');
    const emptyState = document.getElementById('no-messages');
    if (!list) return;

    // Svuota lista
    list.innerHTML = '';

    if (!messages || messages.length === 0) {
        if (emptyState) emptyState.classList.remove('hidden');
        return;
    }
    if (emptyState) emptyState.classList.add('hidden');

    // Ricostruisci timeline
    messages.forEach((message, idx) => {
        const isLast = idx === messages.length - 1;
        const li = document.createElement('li');
        li.innerHTML = `
            <div class="relative pb-8">
                ${!isLast ? '<span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>' : ''}
                <div class="relative flex space-x-3">
                    <div>
                        ${message.sender_type === 'user' ? '<span class="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center ring-8 ring-white"><span class="text-white text-sm font-medium">👤</span></span>' : message.sender_type === 'operator' ? '<span class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center ring-8 ring-white"><span class="text-white text-sm font-medium">👨‍💼</span></span>' : '<span class="h-8 w-8 rounded-full bg-gray-500 flex items-center justify-center ring-8 ring-white"><span class="text-white text-sm font-medium">🤖</span></span>'}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div>
                            <div class="text-sm">
                                <span class="font-medium text-gray-900">${message.sender_type === 'user' ? (message.sender_name || 'Utente') : message.sender_type === 'operator' ? (message.sender_name || 'Operatore') : 'Sistema/Bot'}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-gray-500">${message.sent_at_formatted || message.sent_at}</p>
                        </div>
                        <div class="mt-2 text-sm text-gray-700">
                            <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm whitespace-pre-wrap">${message.content}</div>
                        </div>
                    </div>
                </div>
            </div>`;
        list.appendChild(li);
    });
}

async function refreshTimeline() {
    try {
        const res = await fetch('{{ url('/api/v1/conversations') }}/{{ $session->session_id }}/messages');
        if (!res.ok) return;
        const data = await res.json();
        if (data && Array.isArray(data.messages)) {
            renderMessages(data.messages);
        }
    } catch (e) {
        console.warn('⚠️ Timeline refresh failed:', e.message);
    }
}

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        refreshTimeline();
    }
});

refreshTimer = setInterval(() => {
    if (document.visibilityState === 'visible') {
        refreshTimeline();
    }
}, REFRESH_INTERVAL_MS);

// Auto-refresh per operatori non assegnati
@if($session->assigned_operator_id !== auth()->id())
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 30000);
@endif
</script>
@endpush
@endsection
