@extends('admin.layout')

@section('content')
<div class="flex justify-between items-center mb-6">
  <h1 class="text-2xl font-bold">Nuovo Form Dinamico</h1>
  <a href="{{ route('admin.forms.index') }}" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
    ← Torna ai Form
  </a>
</div>

<form method="POST" action="{{ route('admin.forms.store') }}" class="space-y-6">
  @csrf
  
  <!-- Basic Info -->
  <div class="bg-white rounded-lg shadow-sm border p-6">
    <h2 class="text-lg font-semibold mb-4">📋 Informazioni Base</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Tenant *</label>
        <select name="tenant_id" required class="w-full border rounded-lg px-3 py-2 @error('tenant_id') border-red-500 @enderror">
          <option value="">Seleziona tenant...</option>
          @foreach($tenants as $tenant)
            <option value="{{ $tenant->id }}" @selected(old('tenant_id') == $tenant->id)>
              {{ $tenant->name }}
            </option>
          @endforeach
        </select>
        @error('tenant_id')
          <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
        @enderror
      </div>
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nome Form *</label>
        <input type="text" name="name" value="{{ old('name') }}" required 
               placeholder="es. Richiesta Anagrafe"
               class="w-full border rounded-lg px-3 py-2 @error('name') border-red-500 @enderror">
        @error('name')
          <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
        @enderror
      </div>
    </div>
    
    <div class="mt-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">Descrizione</label>
      <textarea name="description" rows="2" placeholder="Descrizione opzionale del form..."
                class="w-full border rounded-lg px-3 py-2 @error('description') border-red-500 @enderror">{{ old('description') }}</textarea>
      @error('description')
        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
      @enderror
    </div>
    
    <div class="mt-4">
      <label class="flex items-center">
        <input type="checkbox" name="active" value="1" @checked(old('active', true)) 
               class="mr-2">
        <span class="text-sm text-gray-700">Form attivo (gli utenti possono attivarlo)</span>
      </label>
    </div>
  </div>

  <!-- Trigger Settings -->
  <div class="bg-white rounded-lg shadow-sm border p-6">
    <h2 class="text-lg font-semibold mb-4">🎯 Configurazione Trigger</h2>
    
    <div class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Parole Chiave (una per riga)</label>
        <textarea name="trigger_keywords_raw" rows="3" placeholder="anagrafe&#10;documenti&#10;certificato"
                  class="w-full border rounded-lg px-3 py-2">{{ is_array(old('trigger_keywords')) ? implode("\n", old('trigger_keywords')) : '' }}</textarea>
        <p class="text-xs text-gray-500 mt-1">Quando l'utente scrive una di queste parole, il form si attiva automaticamente</p>
      </div>
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Attiva dopo N messaggi</label>
        <input type="number" name="trigger_after_messages" value="{{ old('trigger_after_messages') }}" 
               min="1" max="100" placeholder="es. 3"
               class="w-full border rounded-lg px-3 py-2">
        <p class="text-xs text-gray-500 mt-1">Il form si attiva automaticamente dopo questo numero di messaggi</p>
      </div>
    </div>
  </div>

  <!-- Email Settings -->
  <div class="bg-white rounded-lg shadow-sm border p-6">
    <h2 class="text-lg font-semibold mb-4">📧 Configurazione Email</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Oggetto Email Conferma *</label>
        <input type="text" name="user_confirmation_email_subject" 
               value="{{ old('user_confirmation_email_subject', 'Conferma ricezione richiesta') }}" required
               class="w-full border rounded-lg px-3 py-2">
      </div>
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email Notifica Admin</label>
        <input type="email" name="admin_notification_email" value="{{ old('admin_notification_email') }}"
               placeholder="admin@example.com"
               class="w-full border rounded-lg px-3 py-2">
      </div>
    </div>
    
    <div class="mt-4">
      <label class="block text-sm font-medium text-gray-700 mb-1">Template Email Conferma</label>
      <textarea name="user_confirmation_email_body" rows="4" 
                class="w-full border rounded-lg px-3 py-2">{{ old('user_confirmation_email_body', "Gentile utente,\n\nabbiamo ricevuto la sua richiesta tramite il chatbot.\n\nDati inviati:\n{form_data}\n\nLa contatteremo al più presto.\n\nCordiali saluti,\n{tenant_name}") }}</textarea>
      <p class="text-xs text-gray-500 mt-1">Placeholder disponibili: {tenant_name}, {form_name}, {form_data}</p>
    </div>
  </div>

  <!-- Form Fields -->
  <div class="bg-white rounded-lg shadow-sm border p-6">
    <h2 class="text-lg font-semibold mb-4">📝 Campi del Form</h2>
    
    <div id="form-fields">
      <!-- Template campo -->
      <div class="field-row border rounded-lg p-4 mb-4" data-index="0">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome Campo *</label>
            <input type="text" name="fields[0][name]" placeholder="es. email" required
                   class="w-full border rounded-lg px-3 py-2">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Etichetta *</label>
            <input type="text" name="fields[0][label]" placeholder="es. Indirizzo Email" required
                   class="w-full border rounded-lg px-3 py-2">
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
            <select name="fields[0][type]" required class="w-full border rounded-lg px-3 py-2">
              @foreach($fieldTypes as $type => $label)
                <option value="{{ $type }}">{{ $label }}</option>
              @endforeach
            </select>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Placeholder</label>
            <input type="text" name="fields[0][placeholder]" placeholder="Testo di esempio..."
                   class="w-full border rounded-lg px-3 py-2">
          </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Testo di Aiuto</label>
            <input type="text" name="fields[0][help_text]" placeholder="Informazione aggiuntiva..."
                   class="w-full border rounded-lg px-3 py-2">
          </div>
          
          <div class="flex items-center justify-between">
            <label class="flex items-center">
              <input type="checkbox" name="fields[0][required]" value="1" class="mr-2">
              <span class="text-sm text-gray-700">Campo obbligatorio</span>
            </label>
            
            <button type="button" onclick="removeField(this)" class="text-red-600 hover:text-red-800">
              🗑️ Rimuovi
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <button type="button" onclick="addField()" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700">
      ➕ Aggiungi Campo
    </button>
  </div>

  <!-- Actions -->
  <div class="bg-white rounded-lg shadow-sm border p-6">
    <div class="flex justify-between">
      <a href="{{ route('admin.forms.index') }}" 
         class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400">
        ❌ Annulla
      </a>
      
      <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
        💾 Crea Form
      </button>
    </div>
  </div>
</form>

<script>
let fieldIndex = 1;

function addField() {
  const container = document.getElementById('form-fields');
  const template = container.querySelector('.field-row').cloneNode(true);
  
  // Update indices
  template.setAttribute('data-index', fieldIndex);
  template.querySelectorAll('input, select, textarea').forEach(input => {
    const name = input.getAttribute('name');
    if (name) {
      input.setAttribute('name', name.replace(/\[\d+\]/, `[${fieldIndex}]`));
      input.value = '';
      input.checked = false;
    }
  });
  
  container.appendChild(template);
  fieldIndex++;
}

function removeField(button) {
  const container = document.getElementById('form-fields');
  if (container.querySelectorAll('.field-row').length > 1) {
    button.closest('.field-row').remove();
  } else {
    alert('Deve esserci almeno un campo nel form');
  }
}

// Convert keywords textarea to array on submit
document.querySelector('form').addEventListener('submit', function(e) {
  const keywordsRaw = document.querySelector('textarea[name="trigger_keywords_raw"]').value;
  const keywords = keywordsRaw.split('\n').map(k => k.trim()).filter(k => k);
  
  // Create hidden inputs for keywords array
  keywords.forEach((keyword, index) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = `trigger_keywords[${index}]`;
    input.value = keyword;
    this.appendChild(input);
  });
});
</script>
@endsection
