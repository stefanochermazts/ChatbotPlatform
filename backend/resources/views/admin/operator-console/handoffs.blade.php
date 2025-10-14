@extends('admin.layout')

@section('title', $title)

@section('content')
<div class="w-full py-6">
    <div class="px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">🤝 Richieste Handoff</h1>
                <p class="mt-2 text-sm text-gray-600">
                    Gestisci le richieste di escalation bot→operatore
                </p>
            </div>
            <div class="mt-4 sm:mt-0">
                <a href="{{ route('admin.operator-console.index') }}" 
                   class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    ← Torna al Dashboard
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="mt-6 bg-white shadow rounded-lg p-4">
            <form method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div>
                    <label for="priority" class="block text-sm font-medium text-gray-700">Priorità</label>
                    <select name="priority" id="priority" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="">Tutte</option>
                        <option value="urgent" {{ request('priority') === 'urgent' ? 'selected' : '' }}>🔴 Urgente</option>
                        <option value="high" {{ request('priority') === 'high' ? 'selected' : '' }}>🟡 Alta</option>
                        <option value="normal" {{ request('priority') === 'normal' ? 'selected' : '' }}>🟢 Normale</option>
                        <option value="low" {{ request('priority') === 'low' ? 'selected' : '' }}>🔵 Bassa</option>
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
                <div class="sm:col-span-2 flex items-end">
                    <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        🔍 Filtra
                    </button>
                    <a href="{{ route('admin.operator-console.handoffs') }}" class="ml-2 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Handoffs Table -->
        <div class="mt-6 flow-root">
            <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                    <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Handoff
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Priorità
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tenant
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Età
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Conversazione
                                    </th>
                                    <th scope="col" class="relative px-6 py-3">
                                        <span class="sr-only">Azioni</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($handoffs as $handoff)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    #{{ $handoff->id }}
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    {{ ucfirst($handoff->trigger_type) }}
                                                </div>
                                                @if($handoff->reason)
                                                <div class="text-xs text-gray-400 mt-1">
                                                    {{ Str::limit($handoff->reason, 50) }}
                                                </div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
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
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $handoff->tenant->name ?? 'N/A' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $handoff->getAgeInMinutes() }} min
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            {{ $handoff->conversationSession->user_identifier ?? 'Anonimo' }}
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            {{ $handoff->conversationSession->channel }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="{{ route('admin.operator-console.conversations.show', $handoff->conversationSession) }}" 
                                               class="text-indigo-600 hover:text-indigo-900">
                                                👁️ Visualizza
                                            </a>
                                            
                                            <!-- Quick Assign to Available Operator -->
                                            @php
                                                $availableOperator = \App\Models\User::where('is_operator', true)
                                                    ->where('operator_status', 'available')
                                                    ->whereRaw('current_conversations < max_concurrent_conversations')
                                                    ->first();
                                            @endphp
                                            
                                            @if($availableOperator)
                                            <form method="POST" action="{{ route('admin.operator-console.handoffs.assign', $handoff) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="operator_id" value="{{ $availableOperator->id }}">
                                                <button type="submit" class="text-green-600 hover:text-green-900">
                                                    ✅ Assegna a {{ $availableOperator->name }}
                                                </button>
                                            </form>
                                            @else
                                            <span class="text-gray-400">⏳ Nessun operatore disponibile</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <div class="text-gray-500">
                                            <span class="text-4xl block mb-2">🎉</span>
                                            <h3 class="text-lg font-medium">Nessun handoff pendente</h3>
                                            <p class="text-sm">Tutte le richieste sono state gestite!</p>
                                        </div>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            {{ $handoffs->appends(request()->query())->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
// Auto-refresh ogni 10 secondi
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 10000);
</script>
@endpush
@endsection
