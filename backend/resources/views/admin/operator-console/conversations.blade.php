@extends('admin.layout')

@section('title', $title)

@section('content')
<div class="w-full py-6">
    <div class="px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">💬 Conversazioni Attive</h1>
                <p class="mt-2 text-sm text-gray-600">
                    Monitora e prendi controllo delle conversazioni in tempo reale
                </p>
            </div>
            <div class="mt-4 sm:mt-0 flex space-x-3">
                <a href="{{ route('admin.operator-console.handoffs') }}" 
                   class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    🤝 Handoff Pendenti
                </a>
                <a href="{{ route('admin.operator-console.index') }}" 
                   class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    ← Dashboard
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="mt-6 bg-white shadow rounded-lg p-4">
            <form method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select name="status" id="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">Tutti</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>🟢 Attive (Bot)</option>
                        <option value="assigned" {{ request('status') === 'assigned' ? 'selected' : '' }}>👨‍💼 Assegnate</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>⏳ In Attesa</option>
                    </select>
                </div>
                <div>
                    <label for="tenant_id" class="block text-sm font-medium text-gray-700">Tenant</label>
                    <select name="tenant_id" id="tenant_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">Tutti</option>
                        @foreach(\App\Models\Tenant::all() as $tenant)
                            <option value="{{ $tenant->id }}" {{ request('tenant_id') == $tenant->id ? 'selected' : '' }}>
                                {{ $tenant->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="assigned_to" class="block text-sm font-medium text-gray-700">Operatore</label>
                    <select name="assigned_to" id="assigned_to" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">Tutti</option>
                        @foreach(\App\Models\User::where('is_operator', true)->get() as $operator)
                            <option value="{{ $operator->id }}" {{ request('assigned_to') == $operator->id ? 'selected' : '' }}>
                                {{ $operator->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        🔍 Filtra
                    </button>
                    <a href="{{ route('admin.operator-console.conversations') }}" class="ml-2 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Conversations Grid -->
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-3">
            @forelse($conversations as $conversation)
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <!-- Header -->
                <div class="px-4 py-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <!-- Status Indicator -->
                            @switch($conversation->status)
                                @case('active')
                                    <div class="flex-shrink-0 w-3 h-3 bg-green-400 rounded-full mr-3"></div>
                                    <span class="text-sm font-medium text-green-800">🤖 Bot Attivo</span>
                                    @break
                                @case('assigned')
                                    <div class="flex-shrink-0 w-3 h-3 bg-blue-400 rounded-full mr-3"></div>
                                    <span class="text-sm font-medium text-blue-800">👨‍💼 Operatore</span>
                                    @break
                                @case('pending')
                                    <div class="flex-shrink-0 w-3 h-3 bg-yellow-400 rounded-full mr-3"></div>
                                    <span class="text-sm font-medium text-yellow-800">⏳ In Attesa</span>
                                    @break
                            @endswitch
                        </div>
                        <span class="text-xs text-gray-500">
                            {{ $conversation->last_activity_at->diffForHumans() }}
                        </span>
                    </div>

                    <!-- Conversation Info -->
                    <div class="mt-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ $conversation->user_identifier ?? 'Utente Anonimo' }}
                        </h3>
                        <p class="text-sm text-gray-600">
                            {{ $conversation->tenant->name ?? 'N/A' }} • {{ ucfirst($conversation->channel) }}
                        </p>
                        
                        @if($conversation->assignedOperator)
                        <p class="text-sm text-blue-600 mt-1">
                            👨‍💼 Assegnato a: {{ $conversation->assignedOperator->name }}
                        </p>
                        @endif
                    </div>

                    <!-- Last Message Preview -->
                    @if($conversation->messages->count() > 0)
                    <div class="mt-4 p-3 bg-gray-50 rounded-md">
                        @php $lastMessage = $conversation->messages->first(); @endphp
                        <div class="flex items-center text-xs text-gray-500 mb-1">
                            @switch($lastMessage->sender_type)
                                @case('user')
                                    <span>👤 Utente</span>
                                    @break
                                @case('system')
                                    <span>🤖 Bot</span>
                                    @break
                                @case('operator')
                                    <span>👨‍💼 Operatore</span>
                                    @break
                            @endswitch
                            <span class="ml-2">{{ $lastMessage->sent_at->format('H:i') }}</span>
                        </div>
                        <p class="text-sm text-gray-700">
                            {{ Str::limit($lastMessage->content, 120) }}
                        </p>
                    </div>
                    @endif

                    <!-- Action Buttons -->
                    <div class="mt-6 flex space-x-2">
                        <!-- View Button -->
                        <a href="{{ route('admin.operator-console.conversations.show', $conversation) }}" 
                           class="flex-1 inline-flex justify-center items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            👁️ Visualizza
                        </a>

                        <!-- Take Over / Release Button -->
                        @if($conversation->status === 'active' && in_array($conversation->handoff_status, ['bot_only', 'handoff_requested']))
                            <!-- Can Take Over -->
                            <form method="POST" action="{{ route('admin.operator-console.conversations.takeover', $conversation) }}" class="flex-1">
                                @csrf
                                <button type="submit" 
                                        class="w-full inline-flex justify-center items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                        onclick="return confirm('Vuoi prendere controllo di questa conversazione?')">
                                    🎯 Prendi Controllo
                                </button>
                            </form>
                        @elseif($conversation->assigned_operator_id === auth()->id() && $conversation->handoff_status === 'handoff_active')
                            <!-- Can Release -->
                            <button type="button" 
                                    class="flex-1 inline-flex justify-center items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
                                    onclick="showReleaseModal('{{ $conversation->id }}')">
                                🏁 Rilascia
                            </button>
                        @elseif($conversation->assignedOperator)
                            <!-- Assigned to someone else -->
                            <span class="flex-1 inline-flex justify-center items-center px-3 py-2 border border-gray-300 text-sm leading-4 font-medium rounded-md text-gray-500 bg-gray-100">
                                🔒 Occupato
                            </span>
                        @endif
                        
                        <!-- Delete Button (Admin only) -->
                        @if(auth()->user()->isAdmin())
                        <form method="POST" action="{{ route('admin.operator-console.conversations.delete', $conversation) }}" 
                              class="inline-block">
                            @csrf
                            @method('DELETE')
                            <button type="submit" 
                                    class="inline-flex justify-center items-center px-3 py-2 border border-red-300 shadow-sm text-sm leading-4 font-medium rounded-md text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                                    onclick="return confirm('⚠️ Sei sicuro di voler eliminare completamente questa conversazione?\n\nQuesta azione è irreversibile e cancellerà:\n- Tutti i messaggi\n- La cronologia della conversazione\n- Le richieste di handoff associate')"
                                    title="Solo per amministratori">
                                🗑️ Elimina
                            </button>
                        </form>
                        @endif
                    </div>

                    <!-- Quick Stats -->
                    <div class="mt-4 grid grid-cols-2 gap-4 text-center">
                        <div>
                            <p class="text-xs text-gray-500">Messaggi</p>
                            <p class="text-sm font-medium text-gray-900">{{ $conversation->message_count_total ?? 0 }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Durata</p>
                            <p class="text-sm font-medium text-gray-900">
                                {{ $conversation->started_at->diffForHumans($conversation->last_activity_at, true) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            @empty
            <div class="col-span-full">
                <div class="text-center py-12">
                    <span class="text-6xl block mb-4">💬</span>
                    <h3 class="text-lg font-medium text-gray-900">Nessuna conversazione attiva</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        Quando gli utenti inizieranno a chattare, appariranno qui.
                    </p>
                </div>
            </div>
            @endforelse
        </div>

        <!-- Pagination -->
        <div class="mt-8">
            {{ $conversations->appends(request()->query())->links() }}
        </div>
    </div>
</div>

<!-- Release Modal -->
<div id="releaseModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden" style="z-index: 1000;">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">🏁 Rilascia Conversazione</h3>
            <form id="releaseForm" method="POST">
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
// Release Modal Functions
function showReleaseModal(conversationId) {
    const modal = document.getElementById('releaseModal');
    const form = document.getElementById('releaseForm');
    form.action = `/admin/operator-console/conversations/${conversationId}/release`;
    modal.classList.remove('hidden');
}

function hideReleaseModal() {
    const modal = document.getElementById('releaseModal');
    modal.classList.add('hidden');
}

// Auto-refresh ogni 15 secondi
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 15000);

// Close modal on outside click
document.getElementById('releaseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideReleaseModal();
    }
});
</script>
@endpush
@endsection
