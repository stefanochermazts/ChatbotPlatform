# 🌙 Dark Mode Implementation - Chatbot Widget

## Overview

Il chatbot widget include supporto completo per dark mode, high contrast mode e forced colors mode (Windows High Contrast), garantendo accessibilità WCAG 2.1 AA e compatibilità con le preferenze dell'utente.

## Features

### ✨ Dark Mode Support
- **Auto Detection**: Rileva automaticamente le preferenze di sistema (`prefers-color-scheme`)
- **Manual Toggle**: Pulsante per cambio manuale tra light/dark/auto
- **Persistent Preferences**: Salva le preferenze utente nel localStorage
- **Smooth Transitions**: Transizioni fluide tra i temi (rispetta `prefers-reduced-motion`)

### 🔆 High Contrast Mode
- **System Detection**: Rileva `prefers-contrast: high` automaticamente
- **Enhanced Visibility**: Aumenta contrasti, bordi e indicatori di focus
- **Color Overrides**: Palette di colori ad alto contrasto per light e dark mode
- **Focus Enhancement**: Ring di focus più prominenti e visibili

### 🖥️ Forced Colors Mode
- **Windows High Contrast**: Supporto completo per forced-colors mode
- **System Colors**: Utilizza colori di sistema (`CanvasText`, `ButtonFace`, etc.)
- **Border Enforcement**: Forza bordi visibili su tutti gli elementi
- **No Shadow Mode**: Rimuove ombre che potrebbero causare confusione

## File Structure

```
widget/
├── css/
│   └── chatbot-dark-mode.css    # Stili per tutti i modi tema
├── js/
│   └── chatbot-dark-mode.js     # Manager per gestione temi
└── docs/
    └── dark-mode-implementation.md
```

## Implementation Details

### CSS Architecture

#### Custom Properties
```css
:root {
  --chatbot-theme-mode: light;
  --chatbot-contrast-mode: normal;
  --chatbot-reduce-motion: 0;
}
```

#### Theme Classes
- `.theme-light` - Light mode manuale
- `.theme-dark` - Dark mode manuale  
- `.theme-auto` - Auto mode (segue sistema)
- `.high-contrast-mode` - High contrast attivo
- `.theme-forced-colors` - Forced colors mode

#### Media Queries
```css
@media (prefers-color-scheme: dark) { /* Dark mode auto */ }
@media (prefers-contrast: high) { /* High contrast */ }
@media (forced-colors: active) { /* Forced colors */ }
@media (prefers-reduced-motion: reduce) { /* No animations */ }
```

### JavaScript Manager

#### `ChatbotDarkModeManager` Class

**Constructor**:
```javascript
new ChatbotDarkModeManager(chatbotInstance)
```

**Methods**:
- `getThemeInfo()` - Ottiene info sul tema corrente
- `toggleTheme()` - Cambia tema ciclicamente
- `setTheme(theme)` - Imposta tema specifico ('light'|'dark'|'auto')
- `isDarkMode()` - Controlla se dark mode è attivo
- `isHighContrast()` - Controlla se high contrast è attivo
- `isForcedColors()` - Controlla se forced colors è attivo

**Events**:
- `chatbot:theme:changed` - Evento quando tema cambia
- `chatbot:theme:toggle` - Richiesta di toggle tema
- `chatbot:theme:set` - Richiesta di impostare tema specifico

### Theme Toggle Button

Il widget include un pulsante di toggle automatico nell'header:
- **Icon**: Cambia in base al tema (🌙/☀️/🌗/🎨)
- **Accessible**: Supporto completo keyboard e screen reader
- **Tooltip**: Mostra tema corrente e azione disponibile
- **Disabled State**: Si disabilita in forced colors mode

## Usage Examples

### Basic Integration
Il dark mode si attiva automaticamente quando inclusi i file CSS/JS:

```html
<link rel="stylesheet" href="./css/chatbot-dark-mode.css">
<script src="./js/chatbot-dark-mode.js" defer></script>
```

### Programmatic Control
```javascript
// Accesso al manager
const darkMode = window.chatbotWidget.darkMode;

// Informazioni tema
const info = darkMode.getThemeInfo();
console.log(info.current); // 'light', 'dark', 'dark-high-contrast', etc.

// Cambiare tema
darkMode.setTheme('dark');

// Eventi
document.addEventListener('chatbot:theme:changed', (e) => {
  console.log('New theme:', e.detail.theme);
});
```

### Custom Themes

Per personalizzare i colori dei temi, override delle CSS custom properties:

```css
:root {
  /* Custom dark colors */
  --chatbot-dark-bg-body: #1a1a2e;
  --chatbot-dark-bg-card: #16213e;
  --chatbot-dark-primary-500: #0f3460;
  
  /* Custom light colors */
  --chatbot-light-bg-body: #f8f9fa;
  --chatbot-light-primary-500: #007bff;
}
```

## Accessibility Features

### WCAG 2.1 AA Compliance
- **Contrast Ratios**: Tutti i testi rispettano rapporti di contrasto 4.5:1
- **Focus Indicators**: Indicatori di focus visibili e consistenti
- **Color Independence**: Informazioni non veicolate solo tramite colore
- **Animation Control**: Rispetta `prefers-reduced-motion`

### Screen Reader Support
- **Theme Announcements**: Annuncia cambi di tema agli screen reader
- **Descriptive Labels**: Pulsanti con etichette descrittive
- **State Communication**: Comunica stato corrente del tema

### Keyboard Navigation
- **Tab Support**: Tutti i controlli raggiungibili via tastiera
- **Enter/Space**: Attiva toggle tema con Enter o Spazio
- **Focus Trapping**: Focus rimane nel widget quando aperto

## Browser Support

### Modern Browsers
- **Chrome**: 76+ (prefers-color-scheme, forced-colors)
- **Firefox**: 67+ (prefers-color-scheme), 89+ (forced-colors)
- **Safari**: 12.1+ (prefers-color-scheme)
- **Edge**: 79+ (chromium-based, full support)

### Fallbacks
- **No Media Query Support**: Default light theme
- **No localStorage**: Theme reset ogni sessione
- **Old Browsers**: Graceful degradation senza errori

## Testing

### Manual Testing
1. **System Theme**: Cambia tema OS e verifica auto-detection
2. **Toggle Button**: Testa ciclo light → dark → auto
3. **High Contrast**: Attiva high contrast OS e verifica stili
4. **Keyboard**: Naviga e attiva toggle solo con tastiera
5. **Persistence**: Ricarica pagina e verifica tema salvato

### Browser Dev Tools
```javascript
// Simula dark mode
matchMedia('(prefers-color-scheme: dark)').matches = true;

// Simula high contrast
matchMedia('(prefers-contrast: high)').matches = true;

// Simula forced colors
matchMedia('(forced-colors: active)').matches = true;
```

### Accessibility Testing
- **Screen Reader**: NVDA/JAWS/VoiceOver per annunci
- **Color Blindness**: Simulatori per verifica contrasti
- **High Contrast**: Windows High Contrast mode testing

## Performance

### CSS Optimizations
- **Custom Properties**: Cambio tema senza re-calcolo layout
- **Will-Change**: Ottimizza elementi con transizioni
- **GPU Acceleration**: Transform3d per elementi animati

### JavaScript Optimizations
- **Event Delegation**: Listener unici per media queries
- **Debounced Updates**: Evita aggiornamenti eccessivi
- **Lazy Loading**: Manager inizializzato solo se necessario

## Troubleshooting

### Common Issues

**Theme non si applica**:
- Verificare inclusione `chatbot-dark-mode.css`
- Controllare ordine di caricamento CSS
- Verificare scope CSS custom properties

**Toggle non funziona**:
- Verificare inclusione `chatbot-dark-mode.js`
- Controllare errori console JavaScript
- Verificare inizializzazione manager

**Forced colors non funziona**:
- Testare con Windows High Contrast attivo
- Verificare support media query `forced-colors`
- Controllare override CSS system colors

**Transizioni troppo lente/veloci**:
- Verificare `prefers-reduced-motion` setting
- Modificare durata transizioni CSS
- Disabilitare animazioni se necessario

### Debug Utils
```javascript
// Debug theme info
console.table(window.chatbotWidget.darkMode.getThemeInfo());

// Debug media queries
const queries = [
  '(prefers-color-scheme: dark)',
  '(prefers-contrast: high)', 
  '(forced-colors: active)',
  '(prefers-reduced-motion: reduce)'
];
queries.forEach(q => 
  console.log(q, matchMedia(q).matches)
);
```

## Future Enhancements

### Planned Features
- **Color Temperature**: Supporto per temperature di colore personalizzate
- **Scheduled Themes**: Temi automatici basati su orario
- **Location-Based**: Dark mode basato su sunrise/sunset
- **Theme Variants**: Più varianti di colore per personalizzazione

### API Extensions
- **Theme Presets**: Preset temi predefiniti per diversi brand
- **Dynamic Colors**: Generazione automatica palette da colore principale
- **Accessibility Audit**: Verifica automatica contrasti e accessibilità

---

**Note**: Questa implementazione rispetta le specifiche WCAG 2.1 AA e segue le best practices per accessibilità e user experience moderne.
