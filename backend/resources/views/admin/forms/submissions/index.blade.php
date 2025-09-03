@extends('admin.layout')

@section('content')
<div>
        
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">📋 Form Submissions</h1>
            <p class="mt-2 text-gray-600">Gestisci le richieste inviate tramite i form dinamici</p>
        </div>

        <!-- Stats Cards -->
        @if(isset($stats))
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-yellow-100 rounded-md flex items-center justify-center">
                                <span class="text-yellow-600">⏳</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">In Attesa</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['pending'] ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-blue-100 rounded-md flex items-center justify-center">
                                <span class="text-blue-600">💬</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Risposto</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['responded'] ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-green-100 rounded-md flex items-center justify-center">
                                <span class="text-green-600">✅</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Chiuso</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $stats['closed'] ?? 0 }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <div class="w-8 h-8 bg-gray-100 rounded-md flex items-center justify-center">
                                <span class="text-gray-600">📊</span>
                            </div>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Totale</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ ($stats['pending'] ?? 0) + ($stats['responded'] ?? 0) + ($stats['closed'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Filters -->
        <div class="bg-white shadow rounded-lg p-6 mb-6">
            <form method="GET" action="{{ route('admin.forms.submissions.index') }}" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    
                    <!-- Status Filter -->
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700">Stato</label>
                        <select name="status" id="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            <option value="">Tutti gli stati</option>
                            <option value="pending" {{ ($validated['status'] ?? '') === 'pending' ? 'selected' : '' }}>⏳ In Attesa</option>
                            <option value="responded" {{ ($validated['status'] ?? '') === 'responded' ? 'selected' : '' }}>💬 Risposto</option>
                            <option value="closed" {{ ($validated['status'] ?? '') === 'closed' ? 'selected' : '' }}>✅ Chiuso</option>
                        </select>
                    </div>

                    <!-- Form Filter -->
                    <div>
                        <label for="form_id" class="block text-sm font-medium text-gray-700">Form</label>
                        <select name="form_id" id="form_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            <option value="">Tutti i form</option>
                            @foreach($forms as $form)
                                <option value="{{ $form->id }}" {{ ($validated['form_id'] ?? '') == $form->id ? 'selected' : '' }}>
                                    {{ $form->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Date From -->
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700">Da Data</label>
                        <input type="date" name="date_from" id="date_from" value="{{ $validated['date_from'] ?? '' }}"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <!-- Date To -->
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700">A Data</label>
                        <input type="date" name="date_to" id="date_to" value="{{ $validated['date_to'] ?? '' }}"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <!-- Search -->
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700">Cerca</label>
                        <input type="text" name="search" id="search" value="{{ $validated['search'] ?? '' }}" 
                               placeholder="Session ID, contenuto..."
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>

                    <!-- Per Page -->
                    <div>
                        <label for="per_page" class="block text-sm font-medium text-gray-700">Per Pagina</label>
                        <select name="per_page" id="per_page" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                            <option value="10" {{ ($validated['per_page'] ?? 15) == 10 ? 'selected' : '' }}>10</option>
                            <option value="15" {{ ($validated['per_page'] ?? 15) == 15 ? 'selected' : '' }}>15</option>
                            <option value="25" {{ ($validated['per_page'] ?? 15) == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ ($validated['per_page'] ?? 15) == 50 ? 'selected' : '' }}>50</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-between">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                        🔍 Filtra
                    </button>
                    
                    <a href="{{ route('admin.forms.submissions.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Submissions Table -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    📋 Submissions 
                    @if($submissions->total() > 0)
                        ({{ $submissions->firstItem() }}-{{ $submissions->lastItem() }} di {{ $submissions->total() }})
                    @endif
                </h3>
            </div>

            @if($submissions->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    ID / Form
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Stato
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Contenuto
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Data Invio
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Risposte
                                </th>
                                <th scope="col" class="relative px-6 py-3">
                                    <span class="sr-only">Azioni</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($submissions as $submission)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="text-sm font-medium text-gray-900">
                                                #{{ $submission->id }}
                                            </div>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            {{ $submission->tenantForm->name ?? 'N/A' }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            @if($submission->status === 'pending') bg-yellow-100 text-yellow-800
                                            @elseif($submission->status === 'responded') bg-blue-100 text-blue-800
                                            @elseif($submission->status === 'closed') bg-green-100 text-green-800
                                            @else bg-gray-100 text-gray-800 @endif">
                                            @if($submission->status === 'pending') ⏳ In Attesa
                                            @elseif($submission->status === 'responded') 💬 Risposto
                                            @elseif($submission->status === 'closed') ✅ Chiuso
                                            @else {{ ucfirst($submission->status) }} @endif
                                        </span>
                                    </td>

                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 max-w-xs truncate">
                                            @if($submission->form_data)
                                                @php
                                                    $preview = '';
                                                    foreach($submission->form_data as $field => $value) {
                                                        $preview .= $field . ': ' . (is_array($value) ? implode(', ', $value) : $value) . ' | ';
                                                    }
                                                    $preview = rtrim($preview, ' | ');
                                                @endphp
                                                {{ $preview }}
                                            @else
                                                <span class="text-gray-500 italic">Nessun dato</span>
                                            @endif
                                        </div>
                                        <div class="text-sm text-gray-500 font-mono">
                                            {{ Str::limit($submission->session_id, 20) }}
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $submission->submitted_at->format('d/m/Y H:i') }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        @if($submission->responses_count > 0)
                                            <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs">
                                                {{ $submission->responses_count }} risposte
                                            </span>
                                        @else
                                            <span class="text-gray-400">Nessuna risposta</span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="{{ route('admin.forms.submissions.show', $submission) }}" 
                                               class="text-indigo-600 hover:text-indigo-900">
                                                👁️ Visualizza
                                            </a>
                                            
                                            @if($submission->status !== 'closed')
                                            <a href="{{ route('admin.forms.submissions.respond', $submission) }}" 
                                               class="text-blue-600 hover:text-blue-900">
                                                💬 Rispondi
                                            </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                    {{ $submissions->appends(request()->query())->links() }}
                </div>
            @else
                <div class="text-center py-12">
                    <div class="text-gray-400 text-6xl mb-4">📭</div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Nessuna submission trovata</h3>
                    <p class="text-gray-500">
                        @if(request()->hasAny(['status', 'form_id', 'date_from', 'date_to', 'search']))
                            Prova a modificare i filtri di ricerca.
                        @else
                            Le submissions dei form appariranno qui quando inviate.
                        @endif
                    </p>
                </div>
            @endif
        </div>
</div>
@endsection
