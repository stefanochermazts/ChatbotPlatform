/**
 * 🌙 Chatbot Widget - Dark Mode & High Contrast Manager
 * 
 * Gestisce le modalità di visualizzazione del widget:
 * - Auto detection preferenze sistema
 * - Toggle manuale dark/light mode
 * - High contrast mode support
 * - Forced colors mode compliance
 * - Persistenza preferenze utente
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

(function() {
  'use strict';

  // =================================================================
  // 🌙 DARK MODE MANAGER CLASS
  // =================================================================

  class DarkModeManager {
    constructor(chatbotInstance) {
      this.chatbot = chatbotInstance;
      this.systemPreference = this.getSystemPreference();
      this.userPreference = this.getUserPreference();
      this.highContrastMode = this.getHighContrastPreference();
      this.forcedColorsMode = this.getForcedColorsMode();
      
      // Current active theme
      this.currentTheme = this.determineActiveTheme();
      
      // Storage key for user preferences
      this.storageKey = 'chatbot_theme_preference';
      
      this.init();
    }

    init() {
      this.setupMediaQueryListeners();
      this.createToggleButton();
      this.applyTheme();
      this.setupEventListeners();
    }

    // =================================================================
    // 🔍 PREFERENCE DETECTION
    // =================================================================

    getSystemPreference() {
      if (window.matchMedia) {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      return 'light';
    }

    getHighContrastPreference() {
      if (window.matchMedia) {
        return window.matchMedia('(prefers-contrast: high)').matches;
      }
      return false;
    }

    getForcedColorsMode() {
      if (window.matchMedia) {
        return window.matchMedia('(forced-colors: active)').matches;
      }
      return false;
    }

    getUserPreference() {
      try {
        const stored = localStorage.getItem(this.storageKey);
        if (stored && ['light', 'dark', 'auto'].includes(stored)) {
          return stored;
        }
      } catch (error) {
        console.warn('Could not access localStorage for theme preference:', error);
      }
      return 'auto'; // Default to auto (follow system)
    }

    setUserPreference(preference) {
      try {
        localStorage.setItem(this.storageKey, preference);
        this.userPreference = preference;
        this.updateActiveTheme();
      } catch (error) {
        console.warn('Could not save theme preference:', error);
      }
    }

    determineActiveTheme() {
      if (this.forcedColorsMode) {
        return 'forced-colors';
      }
      
      if (this.userPreference === 'auto') {
        return this.highContrastMode ? `${this.systemPreference}-high-contrast` : this.systemPreference;
      }
      
      return this.highContrastMode ? `${this.userPreference}-high-contrast` : this.userPreference;
    }

    updateActiveTheme() {
      const oldTheme = this.currentTheme;
      this.currentTheme = this.determineActiveTheme();
      
      if (oldTheme !== this.currentTheme) {
        this.applyTheme();
        this.notifyThemeChange();
      }
    }

    // =================================================================
    // 📡 EVENT LISTENERS
    // =================================================================

    setupMediaQueryListeners() {
      // Dark mode preference change
      if (window.matchMedia) {
        const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
        darkModeQuery.addListener((e) => {
          this.systemPreference = e.matches ? 'dark' : 'light';
          if (this.userPreference === 'auto') {
            this.updateActiveTheme();
          }
        });

        // High contrast preference change
        const highContrastQuery = window.matchMedia('(prefers-contrast: high)');
        highContrastQuery.addListener((e) => {
          this.highContrastMode = e.matches;
          this.updateActiveTheme();
        });

        // Forced colors mode change
        const forcedColorsQuery = window.matchMedia('(forced-colors: active)');
        forcedColorsQuery.addListener((e) => {
          this.forcedColorsMode = e.matches;
          this.updateActiveTheme();
        });
      }
    }

    setupEventListeners() {
      // Listen for theme toggle events
      document.addEventListener('chatbot:theme:toggle', (e) => {
        this.toggleTheme();
      });

      document.addEventListener('chatbot:theme:set', (e) => {
        if (e.detail && e.detail.theme) {
          this.setTheme(e.detail.theme);
        }
      });
    }

    // =================================================================
    // 🎨 THEME APPLICATION
    // =================================================================

    applyTheme() {
      const container = document.getElementById('chatbot-container');
      const fab = document.getElementById('chatbot-fab');
      
      if (!container) return;

      // Remove all theme classes
      const themeClasses = [
        'theme-light', 'theme-dark', 'theme-auto',
        'theme-light-high-contrast', 'theme-dark-high-contrast',
        'theme-forced-colors', 'high-contrast-mode'
      ];
      
      container.classList.remove(...themeClasses);
      if (fab) fab.classList.remove(...themeClasses);

      // Apply current theme class
      const themeClass = `theme-${this.currentTheme.replace('-high-contrast', '')}`;
      container.classList.add(themeClass);
      if (fab) fab.classList.add(themeClass);

      // Add high contrast indicator
      if (this.currentTheme.includes('high-contrast')) {
        container.classList.add('high-contrast-mode');
        if (fab) fab.classList.add('high-contrast-mode');
      }

      // Add forced colors indicator
      if (this.forcedColorsMode) {
        container.classList.add('theme-forced-colors');
        if (fab) fab.classList.add('theme-forced-colors');
      }

      // Update CSS custom properties
      this.updateCSSProperties();
      
      // Update toggle button state
      this.updateToggleButton();

      // Announce theme change to accessibility manager
      if (this.chatbot.accessibility) {
        const themeName = this.getThemeDisplayName();
        this.chatbot.accessibility.announce(`Tema cambiato in: ${themeName}`, 'polite');
      }
    }

    updateCSSProperties() {
      const root = document.documentElement;
      
      // Set theme-aware CSS custom properties
      switch (this.currentTheme) {
        case 'dark':
        case 'dark-high-contrast':
          root.style.setProperty('--chatbot-theme-mode', 'dark');
          break;
        case 'light':
        case 'light-high-contrast':
          root.style.setProperty('--chatbot-theme-mode', 'light');
          break;
        case 'forced-colors':
          root.style.setProperty('--chatbot-theme-mode', 'forced');
          break;
        default:
          root.style.setProperty('--chatbot-theme-mode', this.systemPreference);
      }

      // High contrast mode properties
      if (this.highContrastMode || this.currentTheme.includes('high-contrast')) {
        root.style.setProperty('--chatbot-contrast-mode', 'high');
        this.applyHighContrastColors();
      } else {
        root.style.setProperty('--chatbot-contrast-mode', 'normal');
      }

      // Forced colors mode properties
      if (this.forcedColorsMode) {
        this.applyForcedColorsMode();
      }
    }

    applyHighContrastColors() {
      const root = document.documentElement;
      
      if (this.currentTheme.includes('dark')) {
        // Dark high contrast
        root.style.setProperty('--chatbot-text-primary', '#ffffff');
        root.style.setProperty('--chatbot-text-secondary', '#e0e0e0');
        root.style.setProperty('--chatbot-bg-body', '#000000');
        root.style.setProperty('--chatbot-bg-card', '#1a1a1a');
        root.style.setProperty('--chatbot-border-color', '#ffffff');
        root.style.setProperty('--chatbot-primary-500', '#0099ff');
      } else {
        // Light high contrast
        root.style.setProperty('--chatbot-text-primary', '#000000');
        root.style.setProperty('--chatbot-text-secondary', '#333333');
        root.style.setProperty('--chatbot-bg-body', '#ffffff');
        root.style.setProperty('--chatbot-bg-card', '#ffffff');
        root.style.setProperty('--chatbot-border-color', '#000000');
        root.style.setProperty('--chatbot-primary-500', '#0066cc');
      }
    }

    applyForcedColorsMode() {
      const root = document.documentElement;
      
      // Use system colors for forced colors mode
      root.style.setProperty('--chatbot-text-primary', 'CanvasText');
      root.style.setProperty('--chatbot-text-secondary', 'GrayText');
      root.style.setProperty('--chatbot-text-inverted', 'HighlightText');
      root.style.setProperty('--chatbot-bg-body', 'Canvas');
      root.style.setProperty('--chatbot-bg-card', 'Canvas');
      root.style.setProperty('--chatbot-bg-button-primary', 'ButtonFace');
      root.style.setProperty('--chatbot-border-color', 'ButtonText');
      root.style.setProperty('--chatbot-primary-500', 'Highlight');
    }

    // =================================================================
    // 🔄 THEME TOGGLE
    // =================================================================

    createToggleButton() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      // Check if toggle already exists
      if (container.querySelector('.chatbot-theme-toggle')) return;

      const header = container.querySelector('.chatbot-header');
      if (!header) return;

      const toggleButton = document.createElement('button');
      toggleButton.className = 'chatbot-theme-toggle';
      toggleButton.setAttribute('type', 'button');
      toggleButton.setAttribute('aria-label', 'Cambia tema');
      toggleButton.setAttribute('title', 'Cambia tema');
      toggleButton.innerHTML = this.getThemeIcon();

      // Add click handler
      toggleButton.addEventListener('click', () => {
        this.toggleTheme();
      });

      // Add keyboard support
      toggleButton.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.toggleTheme();
        }
      });

      // Insert before close button
      const closeButton = header.querySelector('.chatbot-close-button');
      if (closeButton) {
        header.insertBefore(toggleButton, closeButton);
      } else {
        header.appendChild(toggleButton);
      }
    }

    updateToggleButton() {
      const toggleButton = document.querySelector('.chatbot-theme-toggle');
      if (toggleButton) {
        toggleButton.innerHTML = this.getThemeIcon();
        toggleButton.setAttribute('aria-label', `Cambia tema (attuale: ${this.getThemeDisplayName()})`);
        toggleButton.setAttribute('title', `Cambia tema (attuale: ${this.getThemeDisplayName()})`);
      }
    }

    getThemeIcon() {
      switch (this.currentTheme) {
        case 'dark':
        case 'dark-high-contrast':
          return '☀️'; // Sun icon for switching to light
        case 'light':
        case 'light-high-contrast':
          return '🌙'; // Moon icon for switching to dark
        case 'forced-colors':
          return '🎨'; // Palette icon for forced colors
        default:
          return '🌗'; // Half moon for auto mode
      }
    }

    getThemeDisplayName() {
      switch (this.currentTheme) {
        case 'light':
          return 'Chiaro';
        case 'dark':
          return 'Scuro';
        case 'light-high-contrast':
          return 'Chiaro Alto Contrasto';
        case 'dark-high-contrast':
          return 'Scuro Alto Contrasto';
        case 'forced-colors':
          return 'Colori Sistema';
        default:
          return 'Automatico';
      }
    }

    toggleTheme() {
      // Don't allow manual toggle in forced colors mode
      if (this.forcedColorsMode) {
        if (this.chatbot.accessibility) {
          this.chatbot.accessibility.announce('Modalità tema bloccata dal sistema', 'assertive');
        }
        return;
      }

      let nextPreference;
      
      switch (this.userPreference) {
        case 'auto':
          nextPreference = this.systemPreference === 'dark' ? 'light' : 'dark';
          break;
        case 'light':
          nextPreference = 'dark';
          break;
        case 'dark':
          nextPreference = 'auto';
          break;
        default:
          nextPreference = 'auto';
      }

      this.setTheme(nextPreference);
    }

    setTheme(theme) {
      if (!['light', 'dark', 'auto'].includes(theme)) {
        console.warn('Invalid theme:', theme);
        return;
      }

      this.setUserPreference(theme);
    }

    notifyThemeChange() {
      // Dispatch custom event for theme change
      const event = new CustomEvent('chatbot:theme:changed', {
        detail: {
          theme: this.currentTheme,
          userPreference: this.userPreference,
          systemPreference: this.systemPreference,
          highContrast: this.highContrastMode,
          forcedColors: this.forcedColorsMode
        }
      });
      document.dispatchEvent(event);

      // Notify other widget components
      if (this.chatbot.events) {
        this.chatbot.events.emit('theme-changed', {
          theme: this.currentTheme,
          userPreference: this.userPreference
        });
      }
    }

    // =================================================================
    // 🎨 CSS CLASS HELPERS
    // =================================================================

    addThemeStyles() {
      // Add inline styles for theme toggle button if not already present
      if (!document.getElementById('chatbot-theme-styles')) {
        const styles = document.createElement('style');
        styles.id = 'chatbot-theme-styles';
        styles.textContent = `
          .chatbot-theme-toggle {
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            padding: var(--chatbot-spacing-xs);
            border-radius: var(--chatbot-border-radius-sm);
            transition: background-color 0.2s ease;
            min-width: 32px;
            min-height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: var(--chatbot-spacing-xs);
          }
          
          .chatbot-theme-toggle:hover,
          .chatbot-theme-toggle:focus {
            background-color: rgba(255, 255, 255, 0.2);
            outline: none;
          }
          
          .chatbot-theme-toggle:focus {
            outline: 2px solid var(--chatbot-primary-500);
            outline-offset: 2px;
          }
          
          /* High contrast styles */
          .high-contrast-mode .chatbot-theme-toggle {
            border: 1px solid currentColor;
          }
          
          /* Forced colors styles */
          .theme-forced-colors .chatbot-theme-toggle {
            border: 1px solid ButtonText;
            background-color: ButtonFace;
            color: ButtonText;
          }
          
          .theme-forced-colors .chatbot-theme-toggle:hover,
          .theme-forced-colors .chatbot-theme-toggle:focus {
            background-color: Highlight;
            color: HighlightText;
          }
        `;
        document.head.appendChild(styles);
      }
    }

    // =================================================================
    // 📱 PUBLIC API
    // =================================================================

    // Get current theme info
    getThemeInfo() {
      return {
        current: this.currentTheme,
        userPreference: this.userPreference,
        systemPreference: this.systemPreference,
        highContrast: this.highContrastMode,
        forcedColors: this.forcedColorsMode,
        displayName: this.getThemeDisplayName()
      };
    }

    // Check if dark mode is active
    isDarkMode() {
      return this.currentTheme.includes('dark');
    }

    // Check if high contrast is active
    isHighContrast() {
      return this.highContrastMode || this.currentTheme.includes('high-contrast');
    }

    // Check if forced colors mode is active
    isForcedColors() {
      return this.forcedColorsMode;
    }

    // Enable/disable theme toggle button
    enableToggle(enabled = true) {
      const toggleButton = document.querySelector('.chatbot-theme-toggle');
      if (toggleButton) {
        toggleButton.disabled = !enabled;
        toggleButton.style.display = enabled ? 'flex' : 'none';
      }
    }

    // Clean up
    destroy() {
      const toggleButton = document.querySelector('.chatbot-theme-toggle');
      if (toggleButton) {
        toggleButton.remove();
      }
      
      const styles = document.getElementById('chatbot-theme-styles');
      if (styles) {
        styles.remove();
      }
    }
  }

  // =================================================================
  // 🌐 GLOBAL EXPORT
  // =================================================================

  // Export to global scope for use by chatbot widget
  window.ChatbotDarkModeManager = DarkModeManager;

})();
