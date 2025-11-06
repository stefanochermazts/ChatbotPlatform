# Feature: Testo Link Fonte Configurabile

## Richiesta Utente

Rendere configurabile la dicitura del link alle fonti nel widget, permettendo personalizzazioni come "Approfondisci qui", "Vedi il sito", "Leggi di più", ecc.

## Implementazione

### 1. Database (Migration)

**File**: `backend/database/migrations/2025_11_05_134006_add_source_link_text_to_widget_configs.php`

```php
Schema::table('widget_configs', function (Blueprint $table) {
    $table->string('source_link_text', 100)->nullable()->default('Fonte')->after('welcome_message');
});
```

- Campo nullable con default 'Fonte'
- Max 100 caratteri
- Posizionato dopo `welcome_message`

### 2. Model (WidgetConfig)

**File**: `backend/app/Models/WidgetConfig.php`

```php
protected $fillable = [
    // ...
    'welcome_message',
    'source_link_text',  // NUOVO
    'position',
    // ...
];
```

### 3. Form UI (Edit Widget)

**File**: `backend/resources/views/admin/widget-config/edit.blade.php`

```html
<div class="mt-4">
  <label for="source_link_text" class="block text-sm font-medium text-gray-700">
    Testo Link Fonte
    <span class="text-gray-500 text-xs">(appare nei link alle fonti)</span>
  </label>
  <input type="text" name="source_link_text" id="source_link_text" 
         value="{{ old('source_link_text', $config->source_link_text ?? 'Fonte') }}"
         class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
         placeholder="Fonte"
         maxlength="100">
  <p class="mt-1 text-sm text-gray-500">
    Personalizza il testo del link alle fonti. Es: "Approfondisci qui", "Vedi il sito", "Leggi di più"
  </p>
</div>
```

### 4. Controller Validation

**File**: `backend/app/Http/Controllers/Admin/WidgetConfigController.php`

```php
$validated = $request->validate([
    // Basic Configuration
    'enabled' => 'boolean',
    'widget_name' => 'required|string|max:255',
    'welcome_message' => 'nullable|string|max:1000',
    'source_link_text' => 'nullable|string|max:100',  // NUOVO
    // ...
]);
```

### 5. Context Builder (Utilizzo)

**File**: `backend/app/Services/RAG/ContextBuilder.php`

```php
// 🎨 Load tenant for widget config (source link text)
$tenant = Tenant::find($tenantId);

// ...

// ✅ ADD: Source URL with configurable text
$sourceInfo = '';
if (! empty($c['document_source_url'])) {
    $sourceLinkText = $tenant->widgetConfig->source_link_text ?? 'Fonte';
    $sourceInfo = "\n\n[".$sourceLinkText."](".$c['document_source_url'].')';
}
```

## Flusso Completo

1. **Admin configura il testo**: Admin Panel → Widget Config → Edit → "Testo Link Fonte" → Salva
2. **Valore salvato nel DB**: Campo `source_link_text` in tabella `widget_configs`
3. **ContextBuilder lo usa**: Quando costruisce il contesto per l'LLM, legge il valore dal widget config
4. **Widget visualizza**: Il link alle fonti usa il testo configurato invece di "Fonte"

## Esempi di Personalizzazione

```markdown
Default:
[Fonte](https://www.comune.example.it)

Personalizzazioni:
[Approfondisci qui](https://www.comune.example.it)
[Vedi il sito](https://www.comune.example.it)
[Leggi di più](https://www.comune.example.it)
[Scopri di più](https://www.comune.example.it)
[Vai alla fonte](https://www.comune.example.it)
```

## Test

1. Accedere al Widget Config del tenant
2. Modificare il campo "Testo Link Fonte" (es. "Approfondisci qui")
3. Salvare
4. Testare una query nel widget che genera una risposta con fonte
5. Verificare che il link mostri il testo personalizzato

## Retrocompatibilità

- **Widget esistenti**: Se il campo è NULL o vuoto, usa "Fonte" come default
- **Nessuna breaking change**: Tutti i widget continuano a funzionare come prima
- **Opt-in**: Solo chi modifica il campo vede il testo personalizzato

---

**Data implementazione**: 2025-01-27  
**Files modificati**:
- `backend/database/migrations/2025_11_05_134006_add_source_link_text_to_widget_configs.php`
- `backend/app/Models/WidgetConfig.php`
- `backend/resources/views/admin/widget-config/edit.blade.php`
- `backend/app/Http/Controllers/Admin/WidgetConfigController.php`
- `backend/app/Services/RAG/ContextBuilder.php`

