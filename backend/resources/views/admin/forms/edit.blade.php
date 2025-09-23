@extends('admin.layout')

@section('title', 'Modifica Form - ' . $form->name)

@section('content')
<div class="w-full py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Modifica Form</h1>
            <p class="mt-2 text-gray-600">{{ $form->name }}</p>
        </div>
        <div class="flex space-x-3">
            <a href="{{ route('admin.forms.show', $form) }}" 
               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                ← Torna ai dettagli
            </a>
        </div>
    </div>

    <!-- Alert se ci sono errori -->
    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Ci sono errori nel form:</h3>
                    <div class="mt-2 text-sm text-red-700">
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Form -->
    <form action="{{ route('admin.forms.update', $form) }}" method="POST" class="space-y-8">
        @csrf
        @method('PUT')

        <!-- Informazioni Base -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">📝 Informazioni Base</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Tenant -->
                <div>
                    <label for="tenant_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Tenant <span class="text-red-500">*</span>
                    </label>
                    <select name="tenant_id" id="tenant_id" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Seleziona tenant...</option>
                        @foreach($tenants as $tenant)
                            <option value="{{ $tenant->id }}" 
                                    @selected(old('tenant_id', $form->tenant_id) == $tenant->id)>
                                {{ $tenant->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('tenant_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Stato -->
                <div>
                    <label for="active" class="block text-sm font-medium text-gray-700 mb-2">
                        Stato
                    </label>
                    <div class="flex items-center">
                        <input type="hidden" name="active" value="0">
                        <input type="checkbox" name="active" id="active" value="1" 
                               @checked(old('active', $form->active))
                               class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <label for="active" class="ml-2 text-sm text-gray-700">Form attivo</label>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <!-- Nome Form -->
                <div class="mb-6">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                        Nome Form <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" id="name" 
                           value="{{ old('name', $form->name) }}"
                           placeholder="es. Richiesta Documenti Anagrafe"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Descrizione -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        Descrizione (Interna)
                    </label>
                    <textarea name="description" id="description" rows="3" 
                              placeholder="Descrizione per gli admin..."
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('description', $form->description) }}</textarea>
                    @error('description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Configurazione Trigger -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">🎯 Configurazione Trigger</h3>
            
            <!-- Keywords -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Parole Chiave Trigger
                </label>
                <p class="text-sm text-gray-500 mb-3">Una parola chiave per riga. Il form si attiva quando l'utente scrive una di queste parole.</p>
                <textarea name="trigger_keywords_text" rows="4" 
                          placeholder="anagrafe&#10;documenti&#10;certificato&#10;carta identità"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('trigger_keywords_text', implode("\n", $form->trigger_keywords ?? [])) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">Il sistema controllerà automaticamente se il messaggio dell'utente contiene una di queste parole.</p>
            </div>

            <!-- Message Count -->
            <div class="mb-6">
                <label for="trigger_after_messages" class="block text-sm font-medium text-gray-700 mb-2">
                    Attiva dopo N messaggi
                </label>
                <input type="number" name="trigger_after_messages" id="trigger_after_messages" 
                       value="{{ old('trigger_after_messages', $form->trigger_after_messages) }}"
                       min="1" max="100" placeholder="es. 3"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <p class="mt-1 text-xs text-gray-400">Lascia vuoto per disabilitare. Il form si attiverà automaticamente dopo questo numero di messaggi.</p>
            </div>

            <!-- Question Similarity -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Domande Trigger Specifiche
                </label>
                <p class="text-sm text-gray-500 mb-3">Una domanda per riga. Il form si attiva per domande simili a queste.</p>
                <textarea name="trigger_questions_text" rows="3" 
                          placeholder="Come posso richiedere un certificato?&#10;Che documenti servono per l'anagrafe?"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('trigger_questions_text', implode("\n", $form->trigger_after_questions ?? [])) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">Il sistema userà intelligenza artificiale per riconoscere domande simili.</p>
            </div>
        </div>

        <!-- Configurazione Email -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">📧 Configurazione Email</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Email Subject -->
                <div>
                    <label for="user_confirmation_email_subject" class="block text-sm font-medium text-gray-700 mb-2">
                        Oggetto Email Conferma <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="user_confirmation_email_subject" id="user_confirmation_email_subject" 
                           value="{{ old('user_confirmation_email_subject', $form->user_confirmation_email_subject) }}"
                           placeholder="Conferma ricezione richiesta"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('user_confirmation_email_subject')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Admin Email -->
                <div>
                    <label for="admin_notification_email" class="block text-sm font-medium text-gray-700 mb-2">
                        Email Notifica Admin
                    </label>
                    <input type="email" name="admin_notification_email" id="admin_notification_email" 
                           value="{{ old('admin_notification_email', $form->admin_notification_email) }}"
                           placeholder="admin@esempio.it"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @error('admin_notification_email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Email Body -->
            <div class="mt-6">
                <label for="user_confirmation_email_body" class="block text-sm font-medium text-gray-700 mb-2">
                    Template Email Conferma
                </label>
                <textarea name="user_confirmation_email_body" id="user_confirmation_email_body" rows="6" 
                          placeholder="Gentile utente,&#10;&#10;abbiamo ricevuto la sua richiesta.&#10;&#10;Dati inviati:&#10;{form_data}&#10;&#10;La contatteremo al più presto.&#10;&#10;Cordiali saluti,&#10;{tenant_name}"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('user_confirmation_email_body', $form->user_confirmation_email_body) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">
                    Placeholder disponibili: {tenant_name}, {form_name}, {form_data}, {user_name}, {submission_id}
                </p>
            </div>

            <!-- Auto-risposta -->
            <div class="mt-6">
                <div class="flex items-center mb-3">
                    <input type="hidden" name="auto_response_enabled" value="0">
                    <input type="checkbox" name="auto_response_enabled" id="auto_response_enabled" value="1" 
                           @checked(old('auto_response_enabled', $form->auto_response_enabled))
                           class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <label for="auto_response_enabled" class="ml-2 text-sm font-medium text-gray-700">
                        Abilita auto-risposta immediata
                    </label>
                </div>
                <textarea name="auto_response_message" id="auto_response_message" rows="3" 
                          placeholder="Grazie per la richiesta. Ti risponderemo entro 24 ore."
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">{{ old('auto_response_message', $form->auto_response_message) }}</textarea>
            </div>
        </div>

        <!-- Campi del Form -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">📝 Campi del Form</h3>
                <button type="button" onclick="addField()" 
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm">
                    + Aggiungi Campo
                </button>
            </div>

            <div id="fields-container">
                @foreach($form->fields->sortBy('order') as $index => $field)
                    <div class="field-row border border-gray-200 rounded-lg p-4 mb-4 bg-gray-50">
                        <input type="hidden" name="fields[{{ $index }}][id]" value="{{ $field->id }}">
                        
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="font-medium text-gray-900">Campo {{ $index + 1 }}</h4>
                            <button type="button" onclick="removeField(this)" 
                                    class="text-red-600 hover:text-red-800 text-sm">
                                🗑️ Rimuovi
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Nome Campo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nome Campo *</label>
                                <input type="text" name="fields[{{ $index }}][name]" 
                                       value="{{ old("fields.{$index}.name", $field->name) }}"
                                       placeholder="email, telefono, messaggio..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            </div>

                            <!-- Label -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Etichetta *</label>
                                <input type="text" name="fields[{{ $index }}][label]" 
                                       value="{{ old("fields.{$index}.label", $field->label) }}"
                                       placeholder="Indirizzo Email, Numero di Telefono..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            </div>

                            <!-- Tipo -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                                <select name="fields[{{ $index }}][type]" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                                    @foreach($fieldTypes as $type => $label)
                                        <option value="{{ $type }}" 
                                                @selected(old("fields.{$index}.type", $field->type) === $type)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Placeholder -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Placeholder</label>
                                <input type="text" name="fields[{{ $index }}][placeholder]" 
                                       value="{{ old("fields.{$index}.placeholder", $field->placeholder) }}"
                                       placeholder="Testo di aiuto..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Help Text -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Testo di Aiuto</label>
                                <input type="text" name="fields[{{ $index }}][help_text]" 
                                       value="{{ old("fields.{$index}.help_text", $field->help_text) }}"
                                       placeholder="Informazioni aggiuntive..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            </div>

                            <!-- Required -->
                            <div class="flex items-center">
                                <input type="hidden" name="fields[{{ $index }}][required]" value="0">
                                <input type="checkbox" name="fields[{{ $index }}][required]" value="1" 
                                       @checked(old("fields.{$index}.required", $field->required))
                                       class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                                <label class="ml-2 text-sm text-gray-700">Campo obbligatorio</label>
                            </div>
                        </div>

                        <!-- Opzioni (per select, radio, checkbox) -->
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Opzioni (una per riga, per select/radio/checkbox)
                            </label>
                            <textarea name="fields[{{ $index }}][options_text]" rows="3" 
                                      placeholder="opzione1&#10;opzione2&#10;opzione3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">{{ old("fields.{$index}.options_text", $field->options ? implode("\n", array_values($field->options)) : '') }}</textarea>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Submit -->
        <div class="flex justify-between">
            <a href="{{ route('admin.forms.show', $form) }}" 
               class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg">
                Annulla
            </a>
            <button type="submit" 
                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg">
                💾 Aggiorna Form
            </button>
        </div>
    </form>
</div>

<script>
let fieldIndex = {{ $form->fields->count() }};

function addField() {
    const container = document.getElementById('fields-container');
    const fieldHtml = `
        <div class="field-row border border-gray-200 rounded-lg p-4 mb-4 bg-gray-50">
            <div class="flex justify-between items-center mb-4">
                <h4 class="font-medium text-gray-900">Campo ${fieldIndex + 1}</h4>
                <button type="button" onclick="removeField(this)" 
                        class="text-red-600 hover:text-red-800 text-sm">
                    🗑️ Rimuovi
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nome Campo *</label>
                    <input type="text" name="fields[${fieldIndex}][name]" 
                           placeholder="email, telefono, messaggio..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Etichetta *</label>
                    <input type="text" name="fields[${fieldIndex}][label]" 
                           placeholder="Indirizzo Email, Numero di Telefono..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                    <select name="fields[${fieldIndex}][type]" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        @foreach($fieldTypes as $type => $label)
                            <option value="{{ $type }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Placeholder</label>
                    <input type="text" name="fields[${fieldIndex}][placeholder]" 
                           placeholder="Testo di aiuto..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Testo di Aiuto</label>
                    <input type="text" name="fields[${fieldIndex}][help_text]" 
                           placeholder="Informazioni aggiuntive..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <div class="flex items-center">
                    <input type="hidden" name="fields[${fieldIndex}][required]" value="0">
                    <input type="checkbox" name="fields[${fieldIndex}][required]" value="1" 
                           class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                    <label class="ml-2 text-sm text-gray-700">Campo obbligatorio</label>
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Opzioni (una per riga, per select/radio/checkbox)
                </label>
                <textarea name="fields[${fieldIndex}][options_text]" rows="3" 
                          placeholder="opzione1&#10;opzione2&#10;opzione3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"></textarea>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', fieldHtml);
    fieldIndex++;
}

function removeField(button) {
    const fieldRows = document.querySelectorAll('.field-row');
    if (fieldRows.length > 1) {
        button.closest('.field-row').remove();
    } else {
        alert('Deve esserci almeno un campo nel form.');
    }
}

// Gestione opzioni campi form
document.querySelector('form').addEventListener('submit', function(e) {
    // Solo opzioni per campi - keywords e questions ora gestiti dal controller
    document.querySelectorAll('textarea[name$="[options_text]"]').forEach(textarea => {
        const fieldMatch = textarea.name.match(/fields\[(\d+)\]\[options_text\]/);
        if (fieldMatch) {
            const fieldIndex = fieldMatch[1];
            const options = textarea.value.split('\n')
                .map(o => o.trim())
                .filter(o => o);
            
            // Rimuovi input precedenti per questo campo
            document.querySelectorAll(`input[name^="fields[${fieldIndex}][options]["]`).forEach(input => input.remove());
            
            // Aggiungi nuovi input
            options.forEach((option, index) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `fields[${fieldIndex}][options][${index}]`;
                input.value = option;
                this.appendChild(input);
            });
        }
    });
});
</script>
@endsection
