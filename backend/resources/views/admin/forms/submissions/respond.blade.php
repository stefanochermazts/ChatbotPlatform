@extends('admin.layout')

@section('content')
<div>
        
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">
                        💬 Rispondi a Submission #{{ $submission->id }}
                    </h1>
                    <p class="mt-2 text-gray-600">
                        Form: <strong>{{ $submission->tenantForm->name }}</strong> | 
                        Tenant: <strong>{{ $submission->tenant->name }}</strong>
                    </p>
                </div>
                
                <div class="flex space-x-4">
                    <a href="{{ route('admin.forms.submissions.show', $submission) }}" 
                       class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                        ← Torna ai Dettagli
                    </a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Response Form -->
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-6">📝 Componi Risposta</h2>
                    
                    <form method="POST" action="{{ route('admin.forms.submissions.send-response', $submission) }}">
                        @csrf
                        
                        <!-- Response Type -->
                        <div class="mb-6">
                            <label class="text-base font-medium text-gray-900">Tipo di Risposta</label>
                            <div class="mt-4 space-y-4">
                                <div class="flex items-center">
                                    <input id="response_type_web" name="response_type" type="radio" value="web" 
                                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300" checked>
                                    <label for="response_type_web" class="ml-3 block text-sm font-medium text-gray-700">
                                        🌐 Solo Web (visibile nell'admin)
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input id="response_type_email" name="response_type" type="radio" value="email" 
                                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300">
                                    <label for="response_type_email" class="ml-3 block text-sm font-medium text-gray-700">
                                        📧 Email (invia anche all'utente)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Email Subject (conditional) -->
                        <div id="email_subject_field" class="mb-6" style="display: none;">
                            <label for="email_subject" class="block text-sm font-medium text-gray-700">
                                📧 Oggetto Email *
                            </label>
                            <input type="text" name="email_subject" id="email_subject" 
                                   placeholder="Re: {{ $submission->tenantForm->name }} - Risposta"
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                            @error('email_subject')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Response Content -->
                        <div class="mb-6">
                            <label for="response_content" class="block text-sm font-medium text-gray-700">
                                💬 Contenuto Risposta *
                            </label>
                            <textarea name="response_content" id="response_content" rows="8" 
                                      placeholder="Scrivi qui la tua risposta alla richiesta dell'utente..."
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">{{ old('response_content') }}</textarea>
                            @error('response_content')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            <p class="mt-2 text-sm text-gray-500">Massimo 5000 caratteri</p>
                        </div>

                        <!-- Internal Notes -->
                        <div class="mb-6">
                            <label for="internal_notes" class="block text-sm font-medium text-gray-700">
                                📋 Note Interne (opzionale)
                            </label>
                            <textarea name="internal_notes" id="internal_notes" rows="3" 
                                      placeholder="Note per uso interno, non visibili all'utente..."
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">{{ old('internal_notes') }}</textarea>
                            @error('internal_notes')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Closes Submission -->
                        <div class="mb-6">
                            <div class="flex items-start">
                                <div class="flex items-center h-5">
                                    <input id="closes_submission" name="closes_submission" type="checkbox" value="1"
                                           class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                                </div>
                                <div class="ml-3 text-sm">
                                    <label for="closes_submission" class="font-medium text-gray-700">
                                        ✅ Chiudi submission dopo l'invio
                                    </label>
                                    <p class="text-gray-500">
                                        Segna questa submission come completata e chiusa
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="flex justify-between pt-6 border-t border-gray-200">
                            <a href="{{ route('admin.forms.submissions.show', $submission) }}" 
                               class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                ← Annulla
                            </a>
                            
                            <button type="submit" 
                                    class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded">
                                📤 Invia Risposta
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-1">
                
                <!-- Submission Summary -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">📋 Riepilogo Submission</h3>
                    
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">ID</dt>
                            <dd class="text-sm text-gray-900">#{{ $submission->id }}</dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Data Invio</dt>
                            <dd class="text-sm text-gray-900">
                                {{ $submission->submitted_at->format('d/m/Y H:i:s') }}
                            </dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Stato Attuale</dt>
                            <dd class="text-sm">
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
                            </dd>
                        </div>
                        
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Risposte Precedenti</dt>
                            <dd class="text-sm text-gray-900">
                                {{ $submission->responses()->count() }} risposte
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Form Data Preview -->
                @if($submission->form_data)
                <div class="bg-white shadow rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">📊 Dati Inviati</h3>
                    
                    <dl class="space-y-3">
                        @foreach($submission->form_data as $field => $value)
                            <div>
                                <dt class="text-sm font-medium text-gray-500">
                                    {{ str_replace('_', ' ', ucfirst($field)) }}
                                </dt>
                                <dd class="text-sm text-gray-900 break-words">
                                    @if(is_array($value))
                                        {{ implode(', ', $value) }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
                @endif
            </div>
        </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const webRadio = document.getElementById('response_type_web');
    const emailRadio = document.getElementById('response_type_email');
    const emailSubjectField = document.getElementById('email_subject_field');
    const emailSubjectInput = document.getElementById('email_subject');
    
    function toggleEmailSubject() {
        if (emailRadio.checked) {
            emailSubjectField.style.display = 'block';
            emailSubjectInput.required = true;
        } else {
            emailSubjectField.style.display = 'none';
            emailSubjectInput.required = false;
        }
    }
    
    webRadio.addEventListener('change', toggleEmailSubject);
    emailRadio.addEventListener('change', toggleEmailSubject);
    
    // Initial state
    toggleEmailSubject();
});
</script>
@endsection
