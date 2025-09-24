@extends('admin.layout')

@section('title', $title)

@push('styles')
<style>
    .pulse-animation {
        animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    
    @keyframes pulse {
        0%, 100% {
            opacity: 1;
        }
        50% {
            opacity: .7;
        }
    }
    
    .notification-badge {
        position: relative;
    }
    
    .notification-badge::after {
        content: '';
        position: absolute;
        top: -2px;
        right: -2px;
        width: 8px;
        height: 8px;
        background: #ef4444;
        border-radius: 50%;
        animation: ping 1s cubic-bezier(0, 0, 0.2, 1) infinite;
    }
    
    @keyframes ping {
        75%, 100% {
            transform: scale(2);
            opacity: 0;
        }
    }
</style>
@endpush

@section('content')
<div class="w-full py-6">
    <div class="px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="sm:flex sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">👨‍💼 Operator Console</h1>
                <p class="mt-2 text-sm text-gray-600">
                    Dashboard per la gestione delle conversazioni e handoff bot→operatore
                </p>
            </div>
            <div class="mt-4 sm:mt-0">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                    ✅ Sistema Attivo
                </span>
            </div>
        </div>

        <!-- Quick Stats Cards -->
        <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Handoff Pendenti -->
            <div class="bg-white overflow-hidden shadow rounded-lg {{ $stats['pending_handoffs'] > 0 ? 'ring-2 ring-orange-500' : '' }}">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-orange-500 rounded-md flex items-center justify-center {{ $stats['pending_handoffs'] > 0 ? 'notification-badge' : '' }}">
                                <span class="text-white font-bold">🤝</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Handoff Pendenti</dt>
                                <dd class="text-lg font-medium {{ $stats['pending_handoffs'] > 0 ? 'text-orange-600' : 'text-gray-900' }}">
                                    {{ $stats['pending_handoffs'] }}
                                    @if($stats['pending_handoffs'] > 0)
                                        <span class="text-xs text-orange-500 ml-1">🚨 URGENTI</span>
                                    @endif
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.operator-console.handoffs') }}" class="font-medium text-orange-700 hover:text-orange-900">
                            Gestisci handoff →
                        </a>
                    </div>
                </div>
            </div>

            <!-- Conversazioni Attive -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-blue-500 rounded-md flex items-center justify-center">
                                <span class="text-white font-bold">💬</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Conversazioni Attive</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['active_conversations'] }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.operator-console.conversations') }}" class="font-medium text-blue-700 hover:text-blue-900">
                            Visualizza conversazioni →
                        </a>
                    </div>
                </div>
            </div>

            <!-- Operatori Disponibili -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-green-500 rounded-md flex items-center justify-center">
                                <span class="text-white font-bold">👥</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Operatori Disponibili</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['available_operators'] }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <a href="{{ route('admin.operator-console.operators') }}" class="font-medium text-green-700 hover:text-green-900">
                            Gestisci operatori →
                        </a>
                    </div>
                </div>
            </div>

            <!-- Conversazioni Oggi -->
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-purple-500 rounded-md flex items-center justify-center">
                                <span class="text-white font-bold">📊</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Conversazioni Oggi</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['total_conversations_today'] }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-5 py-3">
                    <div class="text-sm">
                        <span class="text-gray-500">Aggiornato in tempo reale</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 🚨 Urgent Handoff Requests -->
        @if($stats['pending_handoffs'] > 0)
        <div class="mt-8">
            <div class="bg-orange-50 border-l-4 border-orange-400 p-4 rounded-lg">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <span class="text-2xl">🚨</span>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-lg font-medium text-orange-800">
                            {{ $stats['pending_handoffs'] }} Richiesta{{ $stats['pending_handoffs'] > 1 ? 'e' : '' }} di Assistenza Pendente{{ $stats['pending_handoffs'] > 1 ? 'i' : '' }}
                        </h3>
                        <div class="mt-2 text-sm text-orange-700">
                            <p>Ci sono clienti che aspettano un operatore umano. Gestisci le richieste il prima possibile.</p>
                        </div>
                        <div class="mt-4">
                            <a href="{{ route('admin.operator-console.handoffs') }}" 
                               class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 pulse-animation">
                                🤝 Gestisci Richieste Ora
                            </a>
                            <a href="{{ route('admin.operator-console.conversations') }}" 
                               class="ml-3 inline-flex items-center px-4 py-2 border border-orange-300 text-sm font-medium rounded-md text-orange-700 bg-white hover:bg-orange-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                                👀 Visualizza Conversazioni
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Quick Actions -->
        <div class="mt-8">
            <h2 class="text-lg font-medium text-gray-900 mb-4">🚀 Azioni Rapide</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <a href="{{ route('admin.operator-console.handoffs') }}" 
                   class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow border-l-4 border-orange-500">
                    <div class="flex items-center">
                        <span class="text-2xl mr-3">🤝</span>
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">Gestisci Handoff</h3>
                            <p class="text-sm text-gray-600">Assegna richieste agli operatori</p>
                        </div>
                    </div>
                </a>

                <a href="{{ route('admin.operator-console.conversations') }}" 
                   class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow border-l-4 border-blue-500">
                    <div class="flex items-center">
                        <span class="text-2xl mr-3">💬</span>
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">Monitor Conversazioni</h3>
                            <p class="text-sm text-gray-600">Visualizza conversazioni in tempo reale</p>
                        </div>
                    </div>
                </a>

                <a href="{{ route('admin.operator-console.operators') }}" 
                   class="bg-white p-6 rounded-lg shadow hover:shadow-md transition-shadow border-l-4 border-green-500">
                    <div class="flex items-center">
                        <span class="text-2xl mr-3">👥</span>
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">Operatori</h3>
                            <p class="text-sm text-gray-600">Gestisci team e disponibilità</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Real-time Status -->
        <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex items-center">
                <span class="text-blue-600 text-xl mr-3">💡</span>
                <div>
                    <h3 class="text-lg font-medium text-blue-900">Stato Sistema Agent Console</h3>
                    <p class="text-sm text-blue-700 mt-1">
                        Il sistema Agent Console è operativo. Le notifiche real-time e l'assegnazione automatica sono attive.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Auto-refresh stats ogni 30 secondi
setInterval(function() {
    if (document.visibilityState === 'visible') {
        window.location.reload();
    }
}, 30000);
</script>
@endpush
@endsection
