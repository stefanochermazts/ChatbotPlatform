@extends('admin.layout')

@section('content')
<div class="w-full py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">📝 Dettagli Feedback</h1>
            <p class="text-gray-600">Tenant: <strong>{{ $tenant->name }}</strong></p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('admin.tenants.feedback.index', $tenant) }}" 
               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                ← Torna alla Lista
            </a>
        </div>
    </div>

    <!-- Feedback Info -->
    <div class="bg-white border border-gray-200 rounded-lg mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">Rating Feedback</h3>
                <div class="text-right">
                    <div class="text-3xl">{{ $feedback->rating_emoji }}</div>
                    <div class="text-sm text-gray-600">{{ $feedback->rating_text }}</div>
                </div>
            </div>
        </div>
        <div class="p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm font-medium text-gray-500">Data Feedback</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $feedback->feedback_given_at->format('d/m/Y H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-sm font-medium text-gray-500">ID Feedback</dt>
                    <dd class="mt-1 text-sm text-gray-900">#{{ $feedback->id }}</dd>
                </div>
                @if($feedback->session_id)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Session ID</dt>
                    <dd class="mt-1 text-sm font-mono text-gray-900 bg-gray-100 px-2 py-1 rounded">{{ $feedback->session_id }}</dd>
                </div>
                @endif
                @if($feedback->message_id)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Message ID</dt>
                    <dd class="mt-1 text-sm font-mono text-gray-900 bg-gray-100 px-2 py-1 rounded">{{ $feedback->message_id }}</dd>
                </div>
                @endif
            </dl>
        </div>
    </div>

    <!-- Conversazione -->
    <div class="bg-white border border-gray-200 rounded-lg mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">💬 Conversazione</h3>
        </div>
        <div class="p-6 space-y-4">
            <!-- Domanda Utente -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <div class="text-2xl">👤</div>
                    <div class="flex-1">
                        <div class="text-sm font-medium text-blue-900 mb-2">Domanda Utente</div>
                        <div class="text-gray-900">{{ $feedback->user_question }}</div>
                    </div>
                </div>
            </div>

            <!-- Risposta Bot -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <div class="text-2xl">🤖</div>
                    <div class="flex-1">
                        <div class="text-sm font-medium text-gray-700 mb-2">Risposta Bot</div>
                        <div class="text-gray-900 whitespace-pre-wrap">{{ $feedback->bot_response }}</div>
                    </div>
                </div>
            </div>

            @if($feedback->comment)
            <!-- Commento Utente -->
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div class="flex items-start gap-3">
                    <div class="text-2xl">💭</div>
                    <div class="flex-1">
                        <div class="text-sm font-medium text-yellow-900 mb-2">Commento Aggiuntivo</div>
                        <div class="text-gray-900">{{ $feedback->comment }}</div>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- Metadata Tecnico -->
    <div class="bg-white border border-gray-200 rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">🔧 Informazioni Tecniche</h3>
        </div>
        <div class="p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if($feedback->ip_address)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Indirizzo IP</dt>
                    <dd class="mt-1 text-sm font-mono text-gray-900">{{ $feedback->ip_address }}</dd>
                </div>
                @endif
                @if($feedback->page_url)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Pagina URL</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        <a href="{{ $feedback->page_url }}" target="_blank" rel="noopener noreferrer" 
                           class="text-blue-600 hover:text-blue-500 break-all">
                            {{ $feedback->page_url }}
                        </a>
                    </dd>
                </div>
                @endif
                @if($feedback->conversation_id)
                <div>
                    <dt class="text-sm font-medium text-gray-500">Conversation ID</dt>
                    <dd class="mt-1 text-sm font-mono text-gray-900 bg-gray-100 px-2 py-1 rounded">{{ $feedback->conversation_id }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-sm font-medium text-gray-500">Creato il</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $feedback->created_at->format('d/m/Y H:i:s') }}</dd>
                </div>
            </dl>

            @if($feedback->user_agent_data && is_array($feedback->user_agent_data))
            <div class="mt-6">
                <dt class="text-sm font-medium text-gray-500 mb-2">User Agent & Browser Info</dt>
                <dd class="text-sm text-gray-900">
                    <div class="bg-gray-100 p-3 rounded-md">
                        @if(isset($feedback->user_agent_data['user_agent']))
                        <div class="mb-2">
                            <strong>User Agent:</strong><br>
                            <span class="font-mono text-xs">{{ $feedback->user_agent_data['user_agent'] }}</span>
                        </div>
                        @endif
                        @if(isset($feedback->user_agent_data['accept_language']))
                        <div class="mb-2">
                            <strong>Accept Language:</strong> {{ $feedback->user_agent_data['accept_language'] }}
                        </div>
                        @endif
                        @if(isset($feedback->user_agent_data['referer']))
                        <div>
                            <strong>Referer:</strong> {{ $feedback->user_agent_data['referer'] }}
                        </div>
                        @endif
                    </div>
                </dd>
            </div>
            @endif

            @if($feedback->response_metadata && is_array($feedback->response_metadata))
            <div class="mt-6">
                <dt class="text-sm font-medium text-gray-500 mb-2">Response Metadata</dt>
                <dd class="text-sm text-gray-900">
                    <div class="bg-gray-100 p-3 rounded-md">
                        <pre class="text-xs overflow-x-auto">{{ json_encode($feedback->response_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </dd>
            </div>
            @endif
        </div>
    </div>

    <!-- Azioni -->
    <div class="mt-6 flex justify-end gap-3">
        <button onclick="confirmDelete()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
            🗑️ Elimina Feedback
        </button>
    </div>
</div>

<!-- Modal di conferma eliminazione -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Conferma Eliminazione</h3>
        <p class="text-gray-600 mb-6">Sei sicuro di voler eliminare questo feedback? L'azione non può essere annullata.</p>
        <div class="flex justify-end gap-3">
            <button onclick="closeDeleteModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-md text-sm">
                Annulla
            </button>
            <form method="POST" action="{{ route('admin.tenants.feedback.destroy', [$tenant, $feedback]) }}" style="display: inline;">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md text-sm">
                    Elimina
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete() {
    const modal = document.getElementById('deleteModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>
@endsection
