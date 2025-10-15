# 🚨 URGENT FIX: Context Builder Parity Issue

**Status**: 🔴 **CRITICAL BLOCKER**  
**Impact**: Widget gives wrong answers (e.g., wrong phone numbers)  
**Root Cause**: RAG Tester and Widget use **different context building logic**

---

## 🎯 Problem Summary

### What's Happening

**Query**: "telefono comando polizia locale"

- **RAG Tester**: ✅ Returns "**06.95898223**" (CORRECT)
- **Widget**: ❌ Returns "**06/95898211**" (WRONG - hallucinated)

### Why It's Happening

**RAG Tester** (`RagTestController.php` lines 206-244):
```php
// ✅ Adds structured fields EXPLICITLY
if (!empty($c['phone'])) {
    $extra = "\nTelefono: ".$c['phone'];  // ← Explicit phone field!
}
if (!empty($c['email'])) {
    $extra .= "\nEmail: ".$c['email'];
}
// ... etc

// ✅ Uses tenant custom_context_template
if ($tenant && !empty($tenant->custom_context_template)) {
    $contextText = "\n\n" . str_replace('{context}', $rawContext, $tenant->custom_context_template);
}
```

**Widget** (`ContextBuilder.php`):
```php
// ❌ NO structured fields
$parts[] = "[{$title}]\n{$snippet}";  // ← Just title + snippet!
```

**The LLM**:
- In RAG Tester: Sees `Telefono: 06.95898223` (structured field) → Returns correct number
- In Widget: Sees unstructured text, tries to extract number → May hallucinate or pick wrong one

---

## ✅ Solution: Refactor `ContextBuilder`

### Step 1: Update `ContextBuilder` Interface

**File**: `backend/app/Services/RAG/ContextBuilder.php`

**Changes Needed**:
1. Accept `$tenantId` parameter
2. Fetch tenant model to get `custom_context_template`
3. Add structured fields (`phone`, `email`, `address`, `schedule`) from citations
4. Add source URL as `[Fonte: URL]`

### Step 2: Implementation Plan

#### 2.1. Update `build()` Method Signature

**Before**:
```php
public function build(array $citations): array
```

**After**:
```php
public function build(array $citations, int $tenantId, array $options = []): array
```

#### 2.2. Add Structured Fields Logic

**Insert after line 35** (inside foreach loop):

```php
foreach ($unique as $c) {
    $snippet = (string) ($c['snippet'] ?? '');
    
    // Compress if needed
    if ($enabled && mb_strlen($snippet) > $compressOver) {
        $snippet = $this->compressSnippet($snippet, $compressTarget);
    }
    
    $title = (string) ($c['title'] ?? '');
    
    // ✅ ADD: Structured fields
    $extra = '';
    if (!empty($c['phone'])) {
        $extra .= "\nTelefono: " . $c['phone'];
    }
    if (!empty($c['email'])) {
        $extra .= "\nEmail: " . $c['email'];
    }
    if (!empty($c['address'])) {
        $extra .= "\nIndirizzo: " . $c['address'];
    }
    if (!empty($c['schedule'])) {
        $extra .= "\nOrario: " . $c['schedule'];
    }
    
    // ✅ ADD: Source URL
    $sourceInfo = '';
    if (!empty($c['document_source_url'])) {
        $sourceInfo = "\n[Fonte: " . $c['document_source_url'] . "]";
    }
    
    // Combine all parts
    if ($snippet !== '') {
        $parts[] = "[{$title}]\n{$snippet}{$extra}{$sourceInfo}";
    } elseif ($extra !== '') {
        // If no snippet but has structured fields, still include it
        $parts[] = "[{$title}]{$extra}{$sourceInfo}";
    }
}
```

#### 2.3. Apply Custom Context Template

**Replace lines 44-49** with:

```php
// Budget semplice per caratteri
$rawContext = '';
foreach ($parts as $p) {
    if (mb_strlen($rawContext) + mb_strlen($p) + 2 > $maxChars) break;
    $rawContext .= ($rawContext === '' ? '' : "\n\n---\n\n") . $p;
}

// ✅ ADD: Apply tenant custom_context_template
$tenant = \App\Models\Tenant::find($tenantId);
if ($tenant && !empty($tenant->custom_context_template)) {
    $context = "\n\n" . str_replace('{context}', $rawContext, $tenant->custom_context_template);
} else {
    $context = "\n\nContesto (estratti rilevanti):\n" . $rawContext;
}
```

### Step 3: Update `ChatOrchestrationService`

**File**: `backend/app/Services/Chat/ChatOrchestrationService.php`

**Line 177** - Change from:
```php
$contextResult = $this->contextBuilder->build($filteredCitations);
```

**To**:
```php
$contextResult = $this->contextBuilder->build($filteredCitations, $tenantId, [
    'compression_enabled' => false, // Disable for lower latency
]);
```

### Step 4: Update `RagTestController` to Use Unified Service

**File**: `backend/app/Http/Controllers/Admin/RagTestController.php`

**Lines 204-244** - Replace custom context building with:

```php
if ((bool) ($data['with_answer'] ?? false)) {
    // Use unified ContextBuilder service
    $contextBuilder = app(\App\Services\RAG\ContextBuilder::class);
    $contextResult = $contextBuilder->build($citations, $tenantId, [
        'compression_enabled' => false,
    ]);
    $contextText = $contextResult['context'] ?? '';
    
    // Rest of LLM generation logic...
    $messages = [];
    
    if ($tenant && !empty($tenant->custom_system_prompt)) {
        $messages[] = ['role' => 'system', 'content' => $tenant->custom_system_prompt];
    } else {
        $messages[] = ['role' => 'system', 'content' => 'Seleziona solo informazioni...'];
    }
    
    $messages[] = ['role' => 'user', 'content' => "Domanda: ".$data['query']."\n".$contextText];
    
    // ... rest stays the same
}
```

---

## 🧪 Testing Plan

### Manual Test

1. **RAG Tester**:
   ```
   Query: "telefono comando polizia locale"
   Expected: "06.95898223"
   ```

2. **Widget** (via browser console or Postman):
   ```
   POST /v1/chat/completions
   {
     "model": "gpt-4o-mini",
     "messages": [{"role": "user", "content": "telefono comando polizia locale"}]
   }
   Expected: "06.95898223"
   ```

3. **Verify Context Parity**:
   - Enable debug logging in both paths
   - Compare `llm_context` strings
   - Should be IDENTICAL

### Integration Test

```php
// tests/Feature/ChatPipelineParityTest.php

test('widget and rag tester produce identical context', function () {
    $tenant = Tenant::factory()->create();
    $citation = [
        'title' => 'Contatti Polizia Locale',
        'snippet' => 'Il comando si trova in Via Roma.',
        'phone' => '06.95898223',
        'email' => 'polizia@comune.it',
        'document_source_url' => 'https://example.com/contatti',
    ];
    
    // Mock citations in both paths
    // ...
    
    // Call RAG Tester
    $ragResponse = $this->postJson("/admin/tenants/{$tenant->id}/rag-test", [
        'query' => 'telefono polizia locale',
        'with_answer' => true,
    ]);
    
    // Call Widget
    $widgetResponse = $this->postJson('/v1/chat/completions', [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => 'telefono polizia locale']
        ]
    ]);
    
    $ragContext = $ragResponse->json('trace.llm_context');
    $widgetContext = $widgetResponse->json('debug.context'); // Add debug field
    
    expect($ragContext)
        ->toContain('Telefono: 06.95898223')
        ->toContain('Email: polizia@comune.it')
        ->toContain('[Fonte: https://example.com/contatti]');
    
    expect($widgetContext)
        ->toContain('Telefono: 06.95898223')
        ->toContain('Email: polizia@comune.it')
        ->toContain('[Fonte: https://example.com/contatti]');
    
    // Context should be identical
    expect($widgetContext)->toBe($ragContext);
});
```

---

## 📋 Implementation Checklist

- [ ] **Step 1**: Backup current `ContextBuilder.php`
- [ ] **Step 2**: Update `ContextBuilder->build()` signature (add `$tenantId`, `$options`)
- [ ] **Step 3**: Add structured fields logic (phone, email, address, schedule)
- [ ] **Step 4**: Add source URL as `[Fonte: URL]`
- [ ] **Step 5**: Apply tenant `custom_context_template`
- [ ] **Step 6**: Update `ChatOrchestrationService` to pass `$tenantId`
- [ ] **Step 7**: Refactor `RagTestController` to use unified `ContextBuilder`
- [ ] **Step 8**: Run linter (`php artisan pint`)
- [ ] **Step 9**: Manual test - RAG Tester
- [ ] **Step 10**: Manual test - Widget
- [ ] **Step 11**: Compare context strings (should be identical)
- [ ] **Step 12**: Write integration test
- [ ] **Step 13**: Commit & push

---

## ⏱️ Time Estimate

- **Implementation**: 2-3 hours
- **Testing**: 1 hour
- **Total**: 3-4 hours

---

## 🚀 Expected Outcome

After this fix:

✅ RAG Tester and Widget will use **IDENTICAL context building logic**  
✅ Both will include structured fields (phone, email, address, schedule)  
✅ Both will respect tenant `custom_context_template`  
✅ Both will include source URLs  
✅ **Phone number query will return CORRECT result in both paths**

---

## 📚 Related Documentation

- Full Analysis: `.artiforge/report.md`
- RAG Pipeline: `docs/rag.md`
- Context Builder: `backend/app/Services/RAG/ContextBuilder.php`
- RAG Tester: `backend/app/Http/Controllers/Admin/RagTestController.php`
- Chat Orchestrator: `backend/app/Services/Chat/ChatOrchestrationService.php`

---

**NEXT ACTION**: Implement the fix following this plan! 🚀

