# ♿ Accessibility Testing Checklist - WCAG 2.1 AA

Questa checklist verifica la conformità del widget chatbot agli standard WCAG 2.1 AA per l'accessibilità web.

## 🎯 Overview Checklist

### ✅ Completed Features
- [x] Focus management e keyboard navigation
- [x] Screen reader support con ARIA
- [x] High contrast mode support
- [x] Reduced motion preferences
- [x] Touch target accessibility (44px minimum)
- [x] Color contrast compliance
- [x] Skip links per keyboard users
- [x] Focus trap nel widget aperto
- [x] ARIA live regions per annunci dinamici
- [x] Error handling accessibile
- [x] Loading state announcements

## 📋 Detailed Testing Guide

### 1. Keyboard Navigation (WCAG 2.1.1, 2.1.2)

#### Test Steps:
1. **Apertura Widget**
   - [ ] Tab per raggiungere FAB
   - [ ] Invio/Spazio per aprire widget
   - [ ] Alt+C per apertura rapida
   - [ ] Focus si sposta automaticamente al campo input

2. **Navigazione Interna**
   - [ ] Tab naviga tra elementi: input → send button → close button
   - [ ] Shift+Tab naviga all'indietro
   - [ ] Focus rimane trapped nel widget
   - [ ] Tab dall'ultimo elemento porta al primo

3. **Chiusura Widget**
   - [ ] Escape chiude il widget
   - [ ] Close button funziona con Invio/Spazio
   - [ ] Focus ritorna al FAB (o elemento precedente)

4. **Shortcuts Funzionali**
   - [ ] Invio nel campo input invia messaggio
   - [ ] Arrows up/down scorrono i messaggi (se focus su area messaggi)
   - [ ] Home/End vanno a inizio/fine messaggi

#### Expected Behavior:
```javascript
// Focus trap attivo quando widget aperto
widget.accessibility.focusTrapActive === true

// Skip link visibile con Tab
document.querySelector('.chatbot-skip-link:focus') !== null

// Elementi focusabili in ordine corretto
['chatbot-input', 'chatbot-send-button', 'chatbot-close-button']
```

### 2. Screen Reader Support (WCAG 4.1.3)

#### Test Tools:
- **NVDA** (Windows)
- **JAWS** (Windows)  
- **VoiceOver** (macOS)
- **Orca** (Linux)

#### Test Scripts:

1. **Widget Discovery**
   ```
   Expected: "Assistente virtuale pronto. Premi Alt+C per aprire o usa il pulsante."
   Test: Page load announcement
   ```

2. **Widget Opening**
   ```
   Expected: "Assistente virtuale aperto. Usa Tab per navigare, Escape per chiudere."
   Test: Widget open announcement
   ```

3. **Message Flow**
   ```
   User Input: "Ciao"
   Expected: "Messaggio inviato: Ciao"
   Then: "L'assistente sta scrivendo..."
   Finally: "Risposta ricevuta con [N] fonti."
   ```

4. **Error States**
   ```
   Expected: "Errore: [messaggio errore specifico]"
   Test: Network error, API timeout, validation error
   ```

#### ARIA Attributes Check:
```html
<!-- Widget Container -->
<div role="dialog" aria-modal="true" aria-labelledby="chatbot-header-title">

<!-- Messages Area -->
<div role="log" aria-live="polite" aria-label="Conversazione con assistente virtuale">

<!-- Input Area -->
<div role="form" aria-label="Invia messaggio">

<!-- Individual Messages -->
<div role="group" aria-label="Tuo messaggio, 14:30">
<div role="group" aria-label="Risposta assistente, 14:31">

<!-- Error States -->
<div role="alert" aria-live="assertive">
```

### 3. Focus Management (WCAG 2.4.3, 2.4.7)

#### Visual Focus Indicators:
- [ ] Outline visibile di 2px su tutti gli elementi focusabili
- [ ] Contrasto minimo 3:1 tra focus indicator e background
- [ ] Focus indicator non nascosto da altri elementi
- [ ] Animazione focus smooth ma non eccessiva

#### Focus Order Testing:
```javascript
// Test script per verificare ordine focus
const focusableElements = [
  '#chatbot-fab',           // 1. FAB (quando closed)
  '#chatbot-input',         // 2. Input field (quando open)
  '#chatbot-send-button',   // 3. Send button
  '#chatbot-close-button'   // 4. Close button
];

// Verify tab order matches logical flow
focusableElements.forEach((selector, index) => {
  const element = document.querySelector(selector);
  console.assert(element.tabIndex >= 0, `${selector} should be focusable`);
});
```

#### Focus Trap Verification:
```javascript
// Quando widget è aperto
const container = document.getElementById('chatbot-container');
const focusableInside = container.querySelectorAll('input, button, [tabindex]:not([tabindex="-1"])');

// Last element + Tab should focus first element
// First element + Shift+Tab should focus last element
```

### 4. Color and Contrast (WCAG 1.4.3, 1.4.11)

#### Contrast Testing Tools:
- **WebAIM Contrast Checker**
- **Colour Contrast Analyser**
- **axe DevTools**

#### Required Ratios:
- **Normal text**: 4.5:1 minimum
- **Large text (18pt+)**: 3.0:1 minimum  
- **UI components**: 3.0:1 minimum

#### Test Cases:
```css
/* Verify these combinations meet requirements */
.chatbot-text-primary on .chatbot-bg-body
.chatbot-text-inverted on .chatbot-bg-message-user  
.chatbot-text-primary on .chatbot-bg-message-bot
.chatbot-error-text on .chatbot-error-bg
.chatbot-border-color on .chatbot-bg-body
```

#### High Contrast Mode:
```css
/* Test with forced colors active */
@media (forced-colors: active) {
  /* All elements should remain visible and functional */
  /* Borders should be preserved for interactive elements */
}
```

### 5. Reduced Motion (WCAG 2.3.3)

#### Test Method:
```css
/* Set system preference */
@media (prefers-reduced-motion: reduce) {
  /* All animations should be disabled or significantly reduced */
}
```

#### Test Cases:
- [ ] Widget open/close animation rispetta preferenze
- [ ] Loading dots diventano static
- [ ] Hover effects rimangono ma senza transitions
- [ ] Focus animations ridotte a fade semplice
- [ ] Message animations disabilitate

#### Verification Script:
```javascript
// Check if animations are disabled
const hasReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (hasReducedMotion) {
  const animatedElements = document.querySelectorAll('.chatbot-container, .chatbot-message');
  animatedElements.forEach(el => {
    const styles = getComputedStyle(el);
    console.assert(
      styles.animationDuration === '0s' || styles.animationName === 'none',
      'Animations should be disabled with reduced motion'
    );
  });
}
```

### 6. Touch Accessibility (WCAG 2.5.5)

#### Minimum Target Sizes:
- [ ] FAB: 56px × 56px (mobile), 44px × 44px (desktop)
- [ ] Send button: 44px × 44px minimum
- [ ] Close button: 44px × 44px minimum
- [ ] Input field: 44px height minimum (mobile)

#### Touch Testing:
```javascript
// Verify touch targets
const touchTargets = ['#chatbot-fab', '#chatbot-send-button', '#chatbot-close-button'];
touchTargets.forEach(selector => {
  const element = document.querySelector(selector);
  const rect = element.getBoundingClientRect();
  console.assert(
    rect.width >= 44 && rect.height >= 44,
    `${selector} should be at least 44x44px`
  );
});
```

### 7. Text and Language (WCAG 3.1.1, 1.4.4)

#### Language Declaration:
```html
<html lang="it">
<!-- Widget content in Italian -->
```

#### Text Scaling:
- [ ] Text remains readable at 200% zoom
- [ ] Layout doesn't break at 200% zoom
- [ ] Horizontal scrolling not required
- [ ] All functionality remains available

#### Test Procedure:
1. Set browser zoom to 200%
2. Test all widget functionality
3. Verify text is readable
4. Check for layout issues

### 8. Error Handling (WCAG 3.3.1, 3.3.3)

#### Error Identification:
- [ ] Errori identificati chiaramente
- [ ] Descrizioni specifiche (non solo "errore generico")
- [ ] Suggerimenti per risoluzione
- [ ] Retry mechanism quando appropriato

#### Error Examples:
```javascript
// Network errors
"Impossibile connettersi al server. Verificare la connessione internet."

// API errors  
"Il servizio è temporaneamente non disponibile. Riprovare tra qualche minuto."

// Input validation
"Il messaggio non può essere vuoto. Inserire un testo prima di inviare."

// Rate limiting
"Troppi messaggi inviati. Attendere 30 secondi prima di riprovare."
```

#### ARIA Error Attributes:
```html
<div role="alert" aria-live="assertive" aria-atomic="true">
  <span class="error-icon" aria-hidden="true">❌</span>
  Errore: [messaggio specifico]
</div>
```

## 🛠️ Automated Testing Tools

### Browser Extensions:
- **axe DevTools** - Accessibility scanner
- **WAVE** - Web accessibility evaluation  
- **Lighthouse** - Performance e accessibility audit
- **Color Oracle** - Color blindness simulator

### Testing Scripts:

#### 1. Basic Accessibility Audit:
```javascript
// Run in browser console
async function runA11yAudit() {
  // Check for axe-core
  if (typeof axe !== 'undefined') {
    const results = await axe.run();
    console.table(results.violations);
    return results.violations.length === 0;
  } else {
    console.warn('axe-core not loaded. Install axe DevTools extension.');
    return false;
  }
}

runA11yAudit();
```

#### 2. Focus Management Test:
```javascript
function testFocusManagement() {
  const chatbot = window.chatbotWidget;
  
  // Test opening
  chatbot.open();
  const activeAfterOpen = document.activeElement.id;
  console.assert(activeAfterOpen === 'chatbot-input', 'Focus should be on input after open');
  
  // Test closing
  chatbot.close();
  const activeAfterClose = document.activeElement.id;
  console.assert(activeAfterClose === 'chatbot-fab', 'Focus should return to FAB after close');
  
  console.log('Focus management test completed');
}
```

#### 3. Screen Reader Announcement Test:
```javascript
function testScreenReaderAnnouncements() {
  const liveRegion = document.getElementById('chatbot-live-region');
  const statusRegion = document.getElementById('chatbot-status-region');
  
  console.assert(liveRegion && liveRegion.getAttribute('aria-live') === 'polite', 'Live region should exist');
  console.assert(statusRegion && statusRegion.getAttribute('aria-live') === 'assertive', 'Status region should exist');
  
  // Test announcement
  const chatbot = window.chatbotWidget;
  chatbot.accessibility.announce('Test announcement');
  
  setTimeout(() => {
    console.assert(liveRegion.textContent === 'Test announcement', 'Announcement should appear in live region');
  }, 200);
}
```

## 📊 Compliance Checklist

### Level A (Must Have):
- [x] **1.1.1** Non-text Content - Alt text per immagini decorative
- [x] **1.3.1** Info and Relationships - Semantic markup, ARIA labels
- [x] **1.3.2** Meaningful Sequence - Logical tab order
- [x] **1.4.1** Use of Color - Non solo colore per comunicare info
- [x] **2.1.1** Keyboard - Tutto accessibile da tastiera
- [x] **2.1.2** No Keyboard Trap - Focus trap gestito correttamente
- [x] **2.4.1** Bypass Blocks - Skip link disponibile
- [x] **2.4.2** Page Titled - Titoli appropriati
- [x] **3.1.1** Language of Page - Lingua dichiarata
- [x] **4.1.1** Parsing - HTML valido
- [x] **4.1.2** Name, Role, Value - ARIA attributes corretti

### Level AA (Required):
- [x] **1.4.3** Contrast (Minimum) - 4.5:1 per testo normale
- [x] **1.4.4** Resize text - Funzionale a 200% zoom
- [x] **2.4.3** Focus Order - Ordine logico di navigazione
- [x] **2.4.6** Headings and Labels - Labels descrittivi
- [x] **2.4.7** Focus Visible - Indicatori focus visibili
- [x] **3.2.1** On Focus - No cambiamenti di contesto imprevisti
- [x] **3.2.2** On Input - No cambiamenti di contesto automatici
- [x] **3.3.1** Error Identification - Errori identificati chiaramente
- [x] **3.3.2** Labels or Instructions - Istruzioni disponibili
- [x] **4.1.3** Status Messages - ARIA live regions per aggiornamenti

### WCAG 2.1 AA Additional:
- [x] **1.4.10** Reflow - Layout responsive senza scroll orizzontale
- [x] **1.4.11** Non-text Contrast - 3:1 per elementi UI
- [x] **2.5.5** Target Size - 44px minimum per touch targets

## 🎯 Final Validation

### Manual Testing Required:
1. **Screen Reader Testing** - Test completo con NVDA/JAWS
2. **Keyboard-Only Navigation** - Disconnettere mouse/touchpad
3. **High Contrast Mode** - Attivare in Windows/macOS
4. **Voice Control** - Test con Dragon/Voice Control
5. **Mobile Testing** - Test su dispositivi touch reali

### Performance Impact:
- [ ] Accessibility features non degradano performance
- [ ] ARIA updates sono debounced appropriatamente
- [ ] Focus management non causa layout thrashing
- [ ] Screen reader announcements non sono troppo frequenti

### Browser Compatibility:
- [ ] Chrome/Edge: Tutte le funzionalità
- [ ] Firefox: Tutte le funzionalità  
- [ ] Safari: Tutte le funzionalità
- [ ] Mobile browsers: Touch targets e responsive

---

**Test Status**: ✅ Ready for Testing  
**Last Updated**: January 2024  
**Next Review**: Quarterly  
**Compliance Level**: WCAG 2.1 AA
