@extends('admin.layout')

@section('title', 'Dettagli Form - ' . $form->name)

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">{{ $form->name }}</h1>
            <p class="mt-2 text-gray-600">{{ $form->description ?? 'Nessuna descrizione' }}</p>
        </div>
        <div class="flex space-x-3">
            <a href="{{ route('admin.forms.edit', $form) }}" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                ✏️ Modifica
            </a>
            <a href="{{ route('admin.forms.preview', $form) }}" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                👁️ Anteprima
            </a>
            <a href="{{ route('admin.forms.index') }}" 
               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                ← Torna all'elenco
            </a>
        </div>
    </div>

    <!-- Status e Info Generali -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-600">Stato</p>
                    <div class="mt-1 flex items-center">
                        @if($form->active)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                ✅ Attivo
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                ❌ Disattivo
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-600">Sottomissioni Totali</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $stats['total_submissions'] }}</p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    📋
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-600">In Attesa</p>
                    <p class="text-2xl font-bold text-orange-600">{{ $stats['pending_submissions'] }}</p>
                </div>
                <div class="p-3 bg-orange-100 rounded-full">
                    ⏳
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-600">Campi Form</p>
                    <p class="text-2xl font-bold text-gray-900">{{ $form->fields->count() }}</p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    📝
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Configurazione Trigger -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">🎯 Configurazione Trigger</h3>
            
            <!-- Keywords -->
            @if($form->trigger_keywords && count($form->trigger_keywords) > 0)
                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Parole Chiave</h4>
                    <div class="flex flex-wrap gap-2">
                        @foreach($form->trigger_keywords as $keyword)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                {{ $keyword }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Message Count -->
            @if($form->trigger_after_messages)
                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Trigger dopo Messaggi</h4>
                    <p class="text-sm text-gray-600">Attiva dopo {{ $form->trigger_after_messages }} messaggi</p>
                </div>
            @endif

            <!-- Questions -->
            @if($form->trigger_after_questions && count($form->trigger_after_questions) > 0)
                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Domande Trigger</h4>
                    <ul class="text-sm text-gray-600 list-disc list-inside">
                        @foreach($form->trigger_after_questions as $question)
                            <li>{{ $question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!$form->trigger_keywords && !$form->trigger_after_messages && !$form->trigger_after_questions)
                <p class="text-gray-500 italic">Nessun trigger configurato - Solo attivazione manuale</p>
            @endif
        </div>

        <!-- Configurazione Email -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">📧 Configurazione Email</h3>
            
            <!-- Conferma Utente -->
            <div class="mb-4">
                <h4 class="text-sm font-medium text-gray-700 mb-2">Email Conferma Utente</h4>
                <p class="text-sm text-gray-600 mb-1">
                    <strong>Oggetto:</strong> {{ $form->user_confirmation_email_subject ?? 'Default' }}
                </p>
                @if($form->user_confirmation_email_body)
                    <div class="text-sm text-gray-600 bg-gray-50 p-3 rounded border max-h-32 overflow-y-auto">
                        {{ Str::limit($form->user_confirmation_email_body, 200) }}
                    </div>
                @endif
            </div>

            <!-- Admin Notification -->
            <div class="mb-4">
                <h4 class="text-sm font-medium text-gray-700 mb-2">Notifica Admin</h4>
                <p class="text-sm text-gray-600">
                    {{ $form->admin_notification_email ?? 'Non configurata' }}
                </p>
            </div>

            <!-- Auto-risposta -->
            @if($form->auto_response_enabled)
                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Auto-risposta</h4>
                    <div class="text-sm text-gray-600 bg-green-50 p-3 rounded border">
                        {{ $form->auto_response_message }}
                    </div>
                </div>
            @endif

            <!-- Logo -->
            @if($form->email_logo_path)
                <div class="mb-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Logo Email</h4>
                    <p class="text-sm text-gray-600">{{ $form->email_logo_path }}</p>
                </div>
            @endif
        </div>
    </div>

    <!-- Campi del Form -->
    <div class="mt-8 bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">📝 Campi del Form</h3>
        
        @if($form->fields->count() > 0)
            <div class="overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Ordine
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Campo
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Tipo
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Obbligatorio
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Placeholder/Opzioni
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($form->fields->sortBy('order') as $field)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $field->order + 1 }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $field->label }}</div>
                                    <div class="text-sm text-gray-500">{{ $field->name }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                        {{ ucfirst($field->type) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($field->required)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            ✓ Obbligatorio
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            Opzionale
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 max-w-xs">
                                    @if($field->placeholder)
                                        <div class="text-gray-600 italic">{{ Str::limit($field->placeholder, 50) }}</div>
                                    @endif
                                    @if($field->options && is_array($field->options))
                                        <div class="text-xs text-gray-500 mt-1">
                                            {{ count($field->options) }} opzioni: {{ implode(', ', array_slice(array_values($field->options), 0, 3)) }}{{ count($field->options) > 3 ? '...' : '' }}
                                        </div>
                                    @endif
                                    @if($field->help_text)
                                        <div class="text-xs text-blue-600 mt-1">
                                            💡 {{ Str::limit($field->help_text, 50) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500 italic">Nessun campo configurato</p>
        @endif
    </div>

    <!-- Recent Submissions -->
    <div class="mt-8 bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">📊 Sottomissioni Recenti</h3>
            <a href="{{ route('admin.forms.submissions.index', ['form_id' => $form->id]) }}" 
               class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                Vedi tutte →
            </a>
        </div>
        
        @if($form->submissions->count() > 0)
            <div class="overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Data
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Utente
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Status
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Trigger
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Azioni
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($form->submissions->take(5) as $submission)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $submission->submitted_at->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $submission->user_name ?? 'Anonimo' }}</div>
                                    <div class="text-sm text-gray-500">{{ $submission->user_email ?? 'No email' }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                        @if($submission->status === 'pending') bg-yellow-100 text-yellow-800
                                        @elseif($submission->status === 'responded') bg-blue-100 text-blue-800
                                        @else bg-gray-100 text-gray-800 @endif">
                                        {{ $submission->status_label }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $submission->trigger_description }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="{{ route('admin.forms.submissions.show', $submission) }}" 
                                       class="text-indigo-600 hover:text-indigo-900">
                                        Visualizza
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-gray-500 italic">Nessuna sottomissione ancora</p>
        @endif
    </div>

    <!-- Informazioni Tecniche -->
    <div class="mt-8 bg-gray-50 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">🔧 Informazioni Tecniche</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <strong>ID Form:</strong> {{ $form->id }}
            </div>
            <div>
                <strong>Tenant ID:</strong> {{ $form->tenant_id }}
            </div>
            <div>
                <strong>Creato:</strong> {{ $form->created_at->format('d/m/Y H:i') }}
            </div>
            <div>
                <strong>Ultimo Aggiornamento:</strong> {{ $form->updated_at->format('d/m/Y H:i') }}
            </div>
        </div>
    </div>
</div>
@endsection
