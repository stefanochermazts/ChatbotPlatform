/**
 * 🌙 Chatbot Widget - Theme Toggle Manager
 * 
 * Gestisce il toggle tra tema chiaro e scuro con:
 * - Supporto per preferenze utente
 * - Persistenza in localStorage
 * - Animazioni fluide
 * - Accessibilità completa
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

class ChatbotThemeToggle {
  constructor() {
    this.storageKey = 'chatbot-theme';
    this.currentTheme = this.getInitialTheme();
    this.widget = null;
    this.toggleButton = null;
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => this.init());
    } else {
      this.init();
    }
  }

  /**
   * Initialize the theme toggle system
   */
  init() {
    this.log('Theme toggle initializing...');
    
    // Wait for widget to be available
    this.waitForWidget().then(() => {
      this.setupToggleButton();
      this.applyTheme(this.currentTheme, false); // Apply without animation initially
      this.log(`Theme toggle initialized with theme: ${this.currentTheme}`);
    });
  }

  /**
   * Wait for widget to be available in DOM
   */
  waitForWidget() {
    return new Promise((resolve) => {
      const checkWidget = () => {
        this.widget = document.querySelector('#chatbot-widget');
        this.toggleButton = document.querySelector('#chatbot-theme-toggle');
        
        if (this.widget && this.toggleButton) {
          resolve();
        } else {
          setTimeout(checkWidget, 100);
        }
      };
      checkWidget();
    });
  }

  /**
   * Get initial theme from localStorage or system preference
   */
  getInitialTheme() {
    // Check localStorage first
    const stored = localStorage.getItem(this.storageKey);
    if (stored && ['light', 'dark'].includes(stored)) {
      return stored;
    }

    // Fall back to system preference
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark';
    }

    return 'light';
  }

  /**
   * Setup the toggle button event listeners
   */
  setupToggleButton() {
    if (!this.toggleButton) {
      this.log('Toggle button not found');
      return;
    }

    // Click handler
    this.toggleButton.addEventListener('click', () => {
      this.toggle();
    });

    // Keyboard support
    this.toggleButton.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.toggle();
      }
    });

    // Listen for system theme changes
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        // Only auto-switch if user hasn't manually set a preference
        if (!localStorage.getItem(this.storageKey)) {
          const newTheme = e.matches ? 'dark' : 'light';
          this.applyTheme(newTheme);
        }
      });
    }

    this.log('Toggle button setup complete');
  }

  /**
   * Toggle between light and dark themes
   */
  toggle() {
    const newTheme = this.currentTheme === 'light' ? 'dark' : 'light';
    this.applyTheme(newTheme);
    
    // Track the toggle event
    this.trackThemeChange(newTheme);
  }

  /**
   * Apply a specific theme
   * @param {string} theme - 'light' or 'dark'
   * @param {boolean} animate - Whether to animate the transition
   */
  applyTheme(theme, animate = true) {
    if (!this.widget) return;

    this.currentTheme = theme;

    // Always set the data-theme attribute explicitly
    this.widget.setAttribute('data-theme', theme);
    
    // Also set it on the document body for global theming
    document.documentElement.setAttribute('data-chatbot-theme', theme);

    // Update button accessibility
    this.updateButtonAccessibility(theme);

    // Save to localStorage
    localStorage.setItem(this.storageKey, theme);

    // Add transition class for smooth animation
    if (animate) {
      this.widget.classList.add('theme-transition');
      setTimeout(() => {
        this.widget.classList.remove('theme-transition');
      }, 300);
    }

    this.log(`Theme applied: ${theme}`);
  }

  /**
   * Update button accessibility attributes
   * @param {string} theme - Current theme
   */
  updateButtonAccessibility(theme) {
    if (!this.toggleButton) return;

    const isLight = theme === 'light';
    const label = `Cambia tema (attuale: ${isLight ? 'chiaro' : 'scuro'})`;
    const title = `Passa al tema ${isLight ? 'scuro' : 'chiaro'}`;

    this.toggleButton.setAttribute('aria-label', label);
    this.toggleButton.setAttribute('title', title);
  }

  /**
   * Track theme change event for analytics
   * @param {string} newTheme - The new theme
   */
  trackThemeChange(newTheme) {
    try {
      // Try to use the existing analytics system
      if (window.ChatbotWidget && window.ChatbotWidget.trackEvent) {
        window.ChatbotWidget.trackEvent('theme_changed', {
          theme: newTheme,
          previous_theme: this.currentTheme === 'light' ? 'dark' : 'light',
          user_initiated: true
        });
      }
    } catch (error) {
      this.log('Analytics tracking failed:', error);
    }
  }

  /**
   * Get current theme
   * @returns {string} Current theme ('light' or 'dark')
   */
  getCurrentTheme() {
    return this.currentTheme;
  }

  /**
   * Logging utility
   * @param {...any} args - Arguments to log
   */
  log(...args) {
    if (window.ChatbotConfig?.debug) {
      console.log('[ThemeToggle]', ...args);
    }
  }
}

// Auto-initialize if not already done
if (!window.ChatbotThemeToggle) {
  window.ChatbotThemeToggle = new ChatbotThemeToggle();
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
  module.exports = ChatbotThemeToggle;
}
