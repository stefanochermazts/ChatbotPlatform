@extends('admin.layout')

@section('title', 'Anteprima Form - ' . $form->name)

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Anteprima Form</h1>
            <p class="mt-2 text-gray-600">{{ $form->name }}</p>
        </div>
        <div class="flex space-x-3">
            <a href="{{ route('admin.forms.edit', $form) }}" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                ✏️ Modifica
            </a>
            <a href="{{ route('admin.forms.show', $form) }}" 
               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                ← Torna ai dettagli
            </a>
        </div>
    </div>

    <!-- Alert -->
    <div class="bg-blue-50 border border-blue-200 rounded-md p-4 mb-8">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">Modalità Anteprima</h3>
                <div class="mt-2 text-sm text-blue-700">
                    <p>Questa è un'anteprima di come apparirà il form agli utenti nel chatbot. Puoi testare la validazione, ma i dati non verranno salvati.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Info -->
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">ℹ️ Informazioni Form</h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <strong>Stato:</strong> 
                @if($form->active)
                    <span class="text-green-600">✅ Attivo</span>
                @else
                    <span class="text-red-600">❌ Disattivo</span>
                @endif
            </div>
            <div>
                <strong>Campi:</strong> {{ $form->fields->count() }}
            </div>
            <div>
                <strong>Trigger:</strong> 
                @if($form->trigger_keywords || $form->trigger_after_messages || $form->trigger_after_questions)
                    Configurati
                @else
                    Solo manuale
                @endif
            </div>
        </div>

        <!-- Trigger Details -->
        @if($form->trigger_keywords || $form->trigger_after_messages || $form->trigger_after_questions)
            <div class="mt-4 pt-4 border-t border-gray-200">
                <h4 class="text-sm font-medium text-gray-700 mb-2">Dettagli Trigger:</h4>
                <div class="text-sm text-gray-600 space-y-1">
                    @if($form->trigger_keywords && count($form->trigger_keywords) > 0)
                        <div>
                            <strong>Keywords:</strong> {{ implode(', ', $form->trigger_keywords) }}
                        </div>
                    @endif
                    @if($form->trigger_after_messages)
                        <div>
                            <strong>Dopo messaggi:</strong> {{ $form->trigger_after_messages }}
                        </div>
                    @endif
                    @if($form->trigger_after_questions && count($form->trigger_after_questions) > 0)
                        <div>
                            <strong>Domande trigger:</strong> {{ implode('; ', $form->trigger_after_questions) }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <!-- Form Preview -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="bg-gray-50 p-4 rounded-lg mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">🤖 Come appare nel chatbot:</h3>
            <p class="text-sm text-gray-600">Il form apparirà in questo formato nella conversazione con l'utente.</p>
        </div>

        <!-- Simulated Chatbot Form -->
        <div class="max-w-md mx-auto bg-blue-50 border-2 border-blue-200 rounded-lg p-6">
            <div class="mb-4">
                <div class="bg-blue-100 text-blue-800 px-3 py-2 rounded-lg text-sm">
                    <strong>🤖 Assistente:</strong> Perfetto! Ho preparato un form per raccogliere le informazioni necessarie. Compila i campi qui sotto:
                </div>
            </div>

            <!-- Form -->
            <form id="preview-form" class="space-y-4">
                <div class="bg-white p-4 rounded-lg border shadow-sm">
                    <h4 class="font-medium text-gray-900 mb-4 text-center">📝 {{ $form->name }}</h4>
                    
                    @if($form->fields->count() > 0)
                        @foreach($form->fields->sortBy('order') as $field)
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    {{ $field->label }}
                                    @if($field->required)
                                        <span class="text-red-500">*</span>
                                    @endif
                                </label>

                                @if($field->type === 'text' || $field->type === 'email' || $field->type === 'number' || $field->type === 'tel')
                                    <input type="{{ $field->type }}" 
                                           name="{{ $field->name }}"
                                           placeholder="{{ $field->placeholder ?? '' }}"
                                           @if($field->required) required @endif
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">

                                @elseif($field->type === 'textarea')
                                    <textarea name="{{ $field->name }}"
                                              placeholder="{{ $field->placeholder ?? '' }}"
                                              @if($field->required) required @endif
                                              rows="3"
                                              class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>

                                @elseif($field->type === 'select')
                                    <select name="{{ $field->name }}"
                                            @if($field->required) required @endif
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">{{ $field->placeholder ?? 'Seleziona...' }}</option>
                                        @if($field->options && is_array($field->options))
                                            @foreach($field->options as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        @endif
                                    </select>

                                @elseif($field->type === 'radio')
                                    @if($field->options && is_array($field->options))
                                        <div class="space-y-2">
                                            @foreach($field->options as $option)
                                                <label class="flex items-center text-sm">
                                                    <input type="radio" 
                                                           name="{{ $field->name }}" 
                                                           value="{{ $option }}"
                                                           @if($field->required) required @endif
                                                           class="mr-2 text-blue-600">
                                                    {{ $option }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif

                                @elseif($field->type === 'checkbox')
                                    @if($field->options && is_array($field->options))
                                        <div class="space-y-2">
                                            @foreach($field->options as $option)
                                                <label class="flex items-center text-sm">
                                                    <input type="checkbox" 
                                                           name="{{ $field->name }}[]" 
                                                           value="{{ $option }}"
                                                           class="mr-2 text-blue-600">
                                                    {{ $option }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif

                                @elseif($field->type === 'date')
                                    <input type="date" 
                                           name="{{ $field->name }}"
                                           @if($field->required) required @endif
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                @endif

                                @if($field->help_text)
                                    <p class="mt-1 text-xs text-gray-500">💡 {{ $field->help_text }}</p>
                                @endif
                            </div>
                        @endforeach

                        <!-- Form Buttons -->
                        <div class="flex space-x-3 pt-4 border-t">
                            <button type="submit" 
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md text-sm font-medium transition-colors">
                                📤 Invia
                            </button>
                            <button type="button" 
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-700 py-2 px-4 rounded-md text-sm transition-colors">
                                ❌ Annulla
                            </button>
                        </div>
                    @else
                        <p class="text-center text-gray-500 italic">Nessun campo configurato</p>
                    @endif
                </div>

                <!-- Test Results -->
                <div id="test-results" class="hidden mt-4 p-3 rounded-lg"></div>
            </form>
        </div>
    </div>

    <!-- Testing Info -->
    <div class="mt-8 bg-gray-50 rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">🧪 Test del Form</h3>
        <div class="text-sm text-gray-600 space-y-2">
            <p><strong>Puoi testare:</strong></p>
            <ul class="list-disc list-inside space-y-1 ml-4">
                <li>Validazione dei campi obbligatori</li>
                <li>Formati email, numeri, date</li>
                <li>Funzionamento di select, radio, checkbox</li>
                <li>Messaggi di errore</li>
            </ul>
            <p class="mt-4"><strong>Nota:</strong> I dati inseriti nel test NON verranno salvati nel database.</p>
        </div>
    </div>
</div>

<script>
document.getElementById('preview-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const resultsDiv = document.getElementById('test-results');
    const formData = new FormData(this);
    
    // Simula validazione
    let hasErrors = false;
    let errorMessages = [];
    
    // Controllo campi obbligatori
    @foreach($form->fields->where('required', true) as $field)
        @if($field->type === 'checkbox')
            const checkboxes = this.querySelectorAll('input[name="{{ $field->name }}[]"]:checked');
            if (checkboxes.length === 0) {
                hasErrors = true;
                errorMessages.push('{{ $field->label }} è obbligatorio');
            }
        @else
            const field{{ $loop->index }} = this.querySelector('[name="{{ $field->name }}"]');
            if (!field{{ $loop->index }}.value.trim()) {
                hasErrors = true;
                errorMessages.push('{{ $field->label }} è obbligatorio');
            }
        @endif
    @endforeach
    
    if (hasErrors) {
        resultsDiv.className = 'mt-4 p-3 rounded-lg bg-red-50 border border-red-200';
        resultsDiv.innerHTML = `
            <h4 class="font-medium text-red-800 mb-2">❌ Errori di validazione:</h4>
            <ul class="list-disc list-inside text-sm text-red-700">
                ${errorMessages.map(msg => `<li>${msg}</li>`).join('')}
            </ul>
        `;
    } else {
        // Raccoglie dati per mostrare il risultato
        const data = {};
        for (let [key, value] of formData.entries()) {
            if (data[key]) {
                if (Array.isArray(data[key])) {
                    data[key].push(value);
                } else {
                    data[key] = [data[key], value];
                }
            } else {
                data[key] = value;
            }
        }
        
        resultsDiv.className = 'mt-4 p-3 rounded-lg bg-green-50 border border-green-200';
        resultsDiv.innerHTML = `
            <h4 class="font-medium text-green-800 mb-2">✅ Form valido! Dati che verrebbero inviati:</h4>
            <div class="text-sm text-green-700 bg-white p-3 rounded border">
                <pre>${JSON.stringify(data, null, 2)}</pre>
            </div>
            <p class="text-xs text-green-600 mt-2">
                💡 In modalità live, questi dati sarebbero salvati e l'utente riceverebbe l'email di conferma.
            </p>
        `;
        
        // Reset form dopo 3 secondi
        setTimeout(() => {
            this.reset();
            resultsDiv.className = 'hidden mt-4 p-3 rounded-lg';
            resultsDiv.innerHTML = '';
        }, 5000);
    }
    
    resultsDiv.classList.remove('hidden');
    resultsDiv.scrollIntoView({ behavior: 'smooth' });
});

// Simula cancellazione
document.querySelector('button[type="button"]').addEventListener('click', function() {
    if (confirm('Sei sicuro di voler annullare? (In modalità live, l\'utente tornerebbe alla chat normale)')) {
        document.getElementById('preview-form').reset();
        const resultsDiv = document.getElementById('test-results');
        resultsDiv.className = 'mt-4 p-3 rounded-lg bg-gray-50 border border-gray-200';
        resultsDiv.innerHTML = `
            <p class="text-sm text-gray-600">
                🤖 <strong>Assistente:</strong> Form annullato. Come posso aiutarti diversamente?
            </p>
        `;
        resultsDiv.classList.remove('hidden');
        
        setTimeout(() => {
            resultsDiv.classList.add('hidden');
        }, 3000);
    }
});
</script>
@endsection







































