@extends('admin.layout')

@section('content')
<div class="w-full py-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">📝 Feedback Chatbot</h1>
            <p class="text-gray-600">Tenant: <strong>{{ $tenant->name }}</strong></p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('admin.tenants.feedback.export', $tenant) }}{{ request()->getQueryString() ? '?' . request()->getQueryString() : '' }}" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                📥 Esporta CSV
            </a>
            <a href="{{ route('admin.tenants.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                ← Torna ai Tenant
            </a>
        </div>
    </div>

    <!-- Statistiche -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white border border-blue-200 rounded-lg">
            <div class="p-6 text-center">
                <div class="text-3xl font-bold text-blue-600">{{ $stats['total'] }}</div>
                <div class="text-gray-600">Feedback Totali</div>
            </div>
        </div>
        <div class="bg-white border border-green-200 rounded-lg">
            <div class="p-6 text-center">
                <div class="text-3xl font-bold text-green-600">{{ $stats['positive'] }}</div>
                <div class="text-gray-600">😊 Positivi ({{ $stats['positive_percent'] }}%)</div>
            </div>
        </div>
        <div class="bg-white border border-yellow-200 rounded-lg">
            <div class="p-6 text-center">
                <div class="text-3xl font-bold text-yellow-600">{{ $stats['neutral'] }}</div>
                <div class="text-gray-600">😐 Neutri ({{ $stats['neutral_percent'] }}%)</div>
            </div>
        </div>
        <div class="bg-white border border-red-200 rounded-lg">
            <div class="p-6 text-center">
                <div class="text-3xl font-bold text-red-600">{{ $stats['negative'] }}</div>
                <div class="text-gray-600">😡 Negativi ({{ $stats['negative_percent'] }}%)</div>
            </div>
        </div>
    </div>

    <!-- Filtri -->
    <div class="bg-white border border-gray-200 rounded-lg mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">🔍 Filtri</h3>
        </div>
        <div class="p-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="rating" class="block text-sm font-medium text-gray-700 mb-1">Rating</label>
                    <select name="rating" id="rating" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" onchange="this.form.submit()">
                        <option value="">Tutti i rating</option>
                        @foreach($ratings as $value => $label)
                            <option value="{{ $value }}" {{ request('rating') == $value ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Da Data</label>
                    <input type="date" name="date_from" id="date_from" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" 
                           value="{{ request('date_from') }}" onchange="this.form.submit()">
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">A Data</label>
                    <input type="date" name="date_to" id="date_to" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm" 
                           value="{{ request('date_to') }}" onchange="this.form.submit()">
                </div>
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Cerca</label>
                    <div class="flex">
                        <input type="text" name="search" id="search" class="flex-1 border border-gray-300 rounded-l-md px-3 py-2 text-sm" 
                               value="{{ request('search') }}" placeholder="Cerca in domande/risposte...">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-r-md text-sm">
                            🔍
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Filtri attivi -->
    @if(request()->hasAny(['rating', 'date_from', 'date_to', 'search']))
    <div class="mb-4">
        <div class="flex flex-wrap gap-2">
            @if(request('rating'))
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    Rating: {{ $ratings[request('rating')] ?? request('rating') }}
                </span>
            @endif
            @if(request('date_from'))
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    Da: {{ request('date_from') }}
                </span>
            @endif
            @if(request('date_to'))
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    A: {{ request('date_to') }}
                </span>
            @endif
            @if(request('search'))
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Cerca: "{{ Str::limit(request('search'), 30) }}"
                </span>
            @endif
            <a href="{{ route('admin.tenants.feedback.index', $tenant) }}" 
               class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 hover:bg-red-200 transition-colors">
                ✕ Pulisci filtri
            </a>
        </div>
    </div>
    @endif

    <!-- Lista Feedback -->
    <div class="bg-white border border-gray-200 rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-medium text-gray-900">📋 Lista Feedback ({{ $feedbacks->total() }} risultati)</h3>
                <small class="text-gray-500">Ordinati per data (più recenti prima)</small>
            </div>
        </div>
        
        @if($feedbacks->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Rating</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Data</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Domanda Utente</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Risposta Bot</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Sessione</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Azioni</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($feedbacks as $feedback)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <span class="text-2xl" title="{{ $feedback->rating_text }}">
                                    {{ $feedback->rating_emoji }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ $feedback->feedback_given_at->format('d/m/Y') }}
                                <br>
                                <span class="text-gray-500">{{ $feedback->feedback_given_at->format('H:i') }}</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="max-w-xs truncate">
                                    {{ Str::limit($feedback->user_question, 100) }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                <div class="max-w-sm truncate">
                                    {{ Str::limit($feedback->bot_response, 150) }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                @if($feedback->session_id)
                                    <code class="text-xs bg-gray-100 px-1 py-0.5 rounded">{{ Str::limit($feedback->session_id, 12) }}</code>
                                @else
                                    <span class="text-gray-400">N/A</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex flex-col gap-1">
                                    <a href="{{ route('admin.tenants.feedback.show', [$tenant, $feedback]) }}" 
                                       class="text-blue-600 hover:text-blue-900 text-xs">👁️ Visualizza</a>
                                    <button onclick="confirmDelete({{ $feedback->id }})" 
                                            class="text-red-600 hover:text-red-900 text-xs">🗑️ Elimina</button>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Paginazione -->
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $feedbacks->appends(request()->query())->links() }}
            </div>
        @else
            <div class="text-center py-12">
                <div class="mb-4">
                    <span class="text-6xl text-gray-300">💭</span>
                </div>
                <h3 class="text-lg font-medium text-gray-900">Nessun feedback trovato</h3>
                <p class="text-gray-500 mt-2">
                    @if(request()->hasAny(['rating', 'date_from', 'date_to', 'search']))
                        Prova a modificare i filtri o 
                        <a href="{{ route('admin.tenants.feedback.index', $tenant) }}" class="text-blue-600 hover:text-blue-500">rimuovi tutti i filtri</a>.
                    @else
                        Gli utenti non hanno ancora fornito feedback per questo tenant.
                    @endif
                </p>
            </div>
        @endif
    </div>
</div>

<!-- Modal di conferma eliminazione -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50" x-data="{ open: false }" x-show="open">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Conferma Eliminazione</h3>
        <p class="text-gray-600 mb-6">Sei sicuro di voler eliminare questo feedback? L'azione non può essere annullata.</p>
        <div class="flex justify-end gap-3">
            <button onclick="closeDeleteModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-md text-sm">
                Annulla
            </button>
            <form id="deleteForm" method="POST" style="display: inline;">
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
function confirmDelete(feedbackId) {
    const modal = document.getElementById('deleteModal');
    const form = document.getElementById('deleteForm');
    form.action = `{{ route('admin.tenants.feedback.index', $tenant) }}/${feedbackId}`;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Auto-focus sul campo di ricerca se vuoto
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search');
    if (searchInput && !searchInput.value) {
        searchInput.focus();
    }
});
</script>
@endsection