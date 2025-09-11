/**
 * 🎨 Chatbot Widget - Dynamic Theming System
 * 
 * Sistema per personalizzazione dinamica del widget per ogni tenant
 * Supporta loghi, colori, font, dimensioni e layout
 * Compatible con design system CSS custom properties
 * 
 * @version 1.0.0
 */

(function() {
  'use strict';

  // =================================================================
  // 🎨 THEMING ENGINE
  // =================================================================

  class ChatbotThemeEngine {
    constructor() {
      this.themes = new Map();
      this.currentTheme = null;
      this.customProperties = new Map();
      this.init();
    }

    init() {
      this.registerDefaultThemes();
      this.loadStoredThemes();
    }

    // =================================================================
    // 🎨 THEME REGISTRATION
    // =================================================================

    registerTheme(themeId, themeConfig) {
      const theme = this.validateTheme(themeConfig);
      this.themes.set(themeId, theme);
      this.saveThemeToStorage(themeId, theme);
    }

    validateTheme(config) {
      const defaultTheme = {
        // Metadata
        name: 'Custom Theme',
        version: '1.0.0',
        author: 'Tenant',
        description: 'Custom chatbot theme',
        
        // Brand
        brand: {
          name: 'Assistente',
          logo: null,
          favicon: null,
          companyName: 'La tua azienda'
        },
        
        // Colors (CSS custom properties values)
        colors: {
          primary: {
            50: '#eff6ff',
            100: '#dbeafe',
            200: '#bfdbfe',
            300: '#93c5fd',
            400: '#60a5fa',
            500: '#3b82f6',
            600: '#2563eb',
            700: '#1d4ed8',
            800: '#1e40af',
            900: '#1e3a8a'
          },
          secondary: {
            50: '#f8fafc',
            100: '#f1f5f9',
            200: '#e2e8f0',
            300: '#cbd5e1',
            400: '#94a3b8',
            500: '#64748b',
            600: '#475569',
            700: '#334155',
            800: '#1e293b',
            900: '#0f172a'
          }
        },
        
        // Typography
        typography: {
          fontFamily: {
            sans: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            mono: "'JetBrains Mono', 'Consolas', monospace"
          },
          fontSize: {
            xs: '0.75rem',
            sm: '0.875rem',
            base: '1rem',
            lg: '1.125rem',
            xl: '1.25rem',
            '2xl': '1.5rem'
          }
        },
        
        // Layout
        layout: {
          widget: {
            width: '380px',
            height: '600px',
            borderRadius: '1.5rem'
          },
          button: {
            size: '60px',
            offset: '20px'
          },
          message: {
            maxWidth: '280px',
            borderRadius: '0.75rem'
          }
        },
        
        // Behavior
        behavior: {
          autoOpen: false,
          showCloseButton: true,
          showHeader: true,
          showAvatar: true,
          enableDarkMode: true,
          enableAnimations: true
        },
        
        // Custom CSS
        customCSS: '',
        
        // Advanced
        advanced: {
          cssVariables: {},
          customClasses: [],
          headerTemplate: null,
          messageTemplate: null
        }
      };

      return this.deepMerge(defaultTheme, config);
    }

    registerDefaultThemes() {
      // Default blue theme
      this.registerTheme('default', {
        name: 'Default Blue',
        colors: {
          primary: {
            500: '#3b82f6',
            600: '#2563eb',
            700: '#1d4ed8'
          }
        }
      });

      // Corporate theme
      this.registerTheme('corporate', {
        name: 'Corporate',
        colors: {
          primary: {
            500: '#1f2937',
            600: '#111827',
            700: '#030712'
          }
        },
        layout: {
          widget: {
            borderRadius: '0.5rem'
          },
          message: {
            borderRadius: '0.375rem'
          }
        }
      });

      // Friendly theme
      this.registerTheme('friendly', {
        name: 'Friendly',
        colors: {
          primary: {
            500: '#10b981',
            600: '#059669',
            700: '#047857'
          }
        },
        layout: {
          widget: {
            borderRadius: '2rem'
          },
          message: {
            borderRadius: '1.5rem'
          }
        }
      });

      // High contrast theme for accessibility
      this.registerTheme('high-contrast', {
        name: 'High Contrast',
        colors: {
          primary: {
            500: '#000000',
            600: '#000000',
            700: '#000000'
          },
          secondary: {
            100: '#ffffff',
            200: '#f0f0f0',
            800: '#000000',
            900: '#000000'
          }
        },
        behavior: {
          enableAnimations: false
        }
      });
    }

    // =================================================================
    // 🎨 THEME APPLICATION
    // =================================================================

    applyTheme(themeId, widgetElement = null) {
      const theme = this.themes.get(themeId);
      console.log('[WidgetTheme] applyTheme()', { themeId, widgetElementPresent: !!widgetElement, theme });
      if (!theme) {
        console.warn('[WidgetTheme] Theme not found, falling back to default', themeId);
        return false;
      }

      this.currentTheme = themeId;
      const element = widgetElement || document.getElementById('chatbot-widget');
      if (!element) {
        console.warn('Widget element not found');
        return false;
      }

      // Apply CSS custom properties
      this.applyCSSProperties(theme, element || document.documentElement);
      
      // Apply layout changes
      this.applyLayout(theme, element || document.documentElement);
      
      // Apply brand elements
      this.applyBrand(theme, element);
      
      // Apply behavior changes
      this.applyBehavior(theme, element);
      
      // Apply custom CSS
      this.applyCustomCSS(theme, themeId);
      
      // Update data attributes for CSS targeting
      element && element.setAttribute && element.setAttribute('data-theme', themeId);
      
      // Emit theme change event
      this.emitThemeChange(themeId, theme);
      
      // Log CSS variables applied and computed sizes
      const root = document.documentElement;
      const cssWidth = root.style.getPropertyValue('--chatbot-widget-width');
      const cssHeight = root.style.getPropertyValue('--chatbot-widget-height');
      const cssRadius = root.style.getPropertyValue('--chatbot-widget-border-radius');
      const container = document.getElementById('chatbot-widget');
      const computed = container ? window.getComputedStyle(container) : null;
      console.log('[WidgetTheme] Applied CSS vars', { cssWidth, cssHeight, cssRadius });
      if (computed) {
        console.log('[WidgetTheme] Computed container size', { width: computed.width, height: computed.height, borderRadius: computed.borderRadius });
      } else {
        console.warn('[WidgetTheme] container #chatbot-widget not found at applyTheme');
      }
      
      return true;
    }

    applyCSSProperties(theme, element) {
      const root = document.documentElement;
      
      // Color properties
      if (theme.colors.primary) {
        Object.entries(theme.colors.primary).forEach(([shade, color]) => {
          root.style.setProperty(`--chatbot-primary-${shade}`, color);
        });
      }
      
      if (theme.colors.secondary) {
        Object.entries(theme.colors.secondary).forEach(([shade, color]) => {
          root.style.setProperty(`--chatbot-secondary-${shade}`, color);
        });
      }
      
      // Typography properties
      if (theme.typography.fontFamily.sans) {
        root.style.setProperty('--chatbot-font-sans', theme.typography.fontFamily.sans);
      }
      
      if (theme.typography.fontSize) {
        Object.entries(theme.typography.fontSize).forEach(([size, value]) => {
          root.style.setProperty(`--chatbot-text-${size}`, value);
        });
      }
      
      // Layout properties
      if (theme.layout.widget) {
        console.log('[WidgetTheme] Applying layout.widget', theme.layout.widget);
        Object.entries(theme.layout.widget).forEach(([prop, value]) => {
          const cssVar = `--chatbot-widget-${this.camelToKebab(prop)}`;
          root.style.setProperty(cssVar, value);
          console.log('[WidgetTheme] set CSS var', cssVar, '=>', value);

          // Propaga alle variabili responsive dove sensato
          if (prop === 'width') {
            root.style.setProperty('--chatbot-widget-width-mobile', value);
            root.style.setProperty('--chatbot-widget-width-tablet', value);
            root.style.setProperty('--chatbot-widget-width-desktop', value);
            // opzionale: max width
            root.style.setProperty('--chatbot-widget-max-width', value);
          }
          if (prop === 'height') {
            root.style.setProperty('--chatbot-widget-height-mobile', value);
            root.style.setProperty('--chatbot-widget-height-tablet', value);
            root.style.setProperty('--chatbot-widget-height-desktop', value);
            root.style.setProperty('--chatbot-widget-max-height', value);
          }
          if (prop === 'borderRadius') {
            // Allinea ai design tokens usati da CSS/JS responsive
            root.style.setProperty('--chatbot-border-radius-lg', value);
            root.style.setProperty('--chatbot-border-radius-default', value);
          }
        });
      }
      
      if (theme.layout.button) {
        Object.entries(theme.layout.button).forEach(([prop, value]) => {
          const cssVar = `--chatbot-button-${this.camelToKebab(prop)}`;
          root.style.setProperty(cssVar, value);
        });
      }
      
      // Advanced custom CSS variables
      if (theme.advanced.cssVariables) {
        Object.entries(theme.advanced.cssVariables).forEach(([property, value]) => {
          root.style.setProperty(property, value);
        });
      }
    }

    applyLayout(theme, element) {
      // Apply layout-specific classes
      if (theme.layout.widget.borderRadius === '0') {
        element.classList.add('chatbot-square');
      } else {
        element.classList.remove('chatbot-square');
      }
    }

    applyBrand(theme, element) {
      // Update brand name in header
      const titleElement = element.querySelector('#chatbot-title');
      if (titleElement && theme.brand.name) {
        titleElement.textContent = theme.brand.name;
      }
      
      // Update company name in subtitle  
      const subtitleElement = element.querySelector('.chatbot-subtitle');
      if (subtitleElement && theme.brand.companyName) {
        const statusElement = subtitleElement.querySelector('#chatbot-status');
        if (!statusElement) {
          subtitleElement.textContent = theme.brand.companyName;
        }
      }
      
      // Apply logo if provided
      if (theme.brand.logo) {
        this.applyLogo(theme.brand.logo, element);
      }
      
      // Apply favicon
      if (theme.brand.favicon) {
        this.applyFavicon(theme.brand.favicon);
      }
    }

    applyLogo(logoUrl, element) {
      const avatars = element.querySelectorAll('.chatbot-avatar');
      avatars.forEach(avatar => {
        // Create image element
        const img = document.createElement('img');
        img.src = logoUrl;
        img.alt = 'Logo assistente';
        img.style.width = '100%';
        img.style.height = '100%';
        img.style.objectFit = 'cover';
        img.style.borderRadius = 'inherit';
        
        // Replace content
        avatar.innerHTML = '';
        avatar.appendChild(img);
        
        // Handle load errors
        img.onerror = () => {
          avatar.innerHTML = '🤖'; // Fallback to emoji
        };
      });
    }

    applyFavicon(faviconUrl) {
      // Update or create favicon
      let link = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
      
      if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
      }
      
      link.href = faviconUrl;
    }

    applyBehavior(theme, element) {
      // Show/hide header
      const header = element.querySelector('.chatbot-header');
      if (header) {
        header.style.display = theme.behavior.showHeader ? '' : 'none';
      }
      
      // Show/hide close button
      const closeBtn = element.querySelector('.chatbot-close-button');
      if (closeBtn) {
        closeBtn.style.display = theme.behavior.showCloseButton ? '' : 'none';
      }
      
      // Show/hide avatars
      const avatars = element.querySelectorAll('.chatbot-message-avatar');
      avatars.forEach(avatar => {
        avatar.style.display = theme.behavior.showAvatar ? '' : 'none';
      });
      
      // Disable animations if requested
      if (!theme.behavior.enableAnimations) {
        element.classList.add('reduce-motion');
      }
      
      // Dark mode preference
      if (theme.behavior.enableDarkMode === false) {
        element.classList.remove('chatbot-dark');
      }
    }

    applyCustomCSS(theme, themeId) {
      if (!theme.customCSS) return;
      
      // Remove previous custom CSS
      const existingStyle = document.getElementById(`chatbot-custom-css-${this.currentTheme}`);
      if (existingStyle) {
        existingStyle.remove();
      }
      
      // Add new custom CSS
      const style = document.createElement('style');
      style.id = `chatbot-custom-css-${themeId}`;
      style.textContent = theme.customCSS;
      document.head.appendChild(style);
    }

    // =================================================================
    // 📁 TENANT CONFIGURATION
    // =================================================================

    createTenantTheme(tenantId, config) {
      const themeId = `tenant-${tenantId}`;
      this.registerTheme(themeId, config);
      return themeId;
    }

    loadTenantTheme(tenantId) {
      const themeId = `tenant-${tenantId}`;
      
      // Try to load from storage first
      const stored = this.loadThemeFromStorage(themeId);
      if (stored) {
        this.themes.set(themeId, stored);
        return Promise.resolve(themeId);
      }
      
      // Try to fetch from server
      return this.fetchTenantTheme(tenantId);
    }

    async fetchTenantTheme(tenantId) {
      // Check if theme API is disabled
      if (window.chatbotConfig && window.chatbotConfig.enableThemeAPI === false) {
        console.log('[WidgetTheme] Theme API disabled, using default theme');
        return Promise.resolve('default');
      }
      
      // Check for demo/test tenant
      if (tenantId && (tenantId.includes('demo') || tenantId.includes('test') || tenantId === 'debug')) {
        console.log('[WidgetTheme] Demo tenant detected, using default theme');
        return Promise.resolve('default');
      }

      try {
        const url = `/api/v1/tenants/${tenantId}/widget-theme`;
        console.log('[WidgetTheme] Fetching tenant theme', { url, tenantId });
        const response = await fetch(url);
        if (response.ok) {
          const themeConfig = await response.json();
          console.log('[WidgetTheme] Theme fetched', themeConfig?.layout?.widget);
          return this.createTenantTheme(tenantId, themeConfig);
        }
        console.warn('[WidgetTheme] Theme fetch non-OK', response.status, '- using default theme');
      } catch (error) {
        console.warn('[WidgetTheme] Could not fetch tenant theme:', error.message, '- using default theme');
      }

      return Promise.resolve('default');
    }

    // =================================================================
    // 💾 STORAGE MANAGEMENT
    // =================================================================

    saveThemeToStorage(themeId, theme) {
      try {
        const key = `chatbot_theme_${themeId}`;
        localStorage.setItem(key, JSON.stringify(theme));
      } catch (error) {
        console.warn('Could not save theme to storage:', error);
      }
    }

    loadThemeFromStorage(themeId) {
      try {
        const key = `chatbot_theme_${themeId}`;
        const stored = localStorage.getItem(key);
        return stored ? JSON.parse(stored) : null;
      } catch (error) {
        console.warn('Could not load theme from storage:', error);
        return null;
      }
    }

    loadStoredThemes() {
      // Load all stored themes
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith('chatbot_theme_')) {
          const themeId = key.replace('chatbot_theme_', '');
          const theme = this.loadThemeFromStorage(themeId);
          if (theme) {
            this.themes.set(themeId, theme);
          }
        }
      }
    }

    // =================================================================
    // 🎨 THEME UTILITIES
    // =================================================================

    getTheme(themeId) {
      return this.themes.get(themeId);
    }

    getAllThemes() {
      return Array.from(this.themes.entries()).map(([id, theme]) => ({
        id,
        name: theme.name,
        description: theme.description
      }));
    }

    getCurrentTheme() {
      return this.currentTheme;
    }

    removeTheme(themeId) {
      this.themes.delete(themeId);
      
      // Remove from storage
      const key = `chatbot_theme_${themeId}`;
      localStorage.removeItem(key);
      
      // Remove custom CSS
      const style = document.getElementById(`chatbot-custom-css-${themeId}`);
      if (style) {
        style.remove();
      }
    }

    // =================================================================
    // 🛠️ UTILITY METHODS
    // =================================================================

    deepMerge(target, source) {
      const result = { ...target };
      
      for (const key in source) {
        if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
          result[key] = this.deepMerge(target[key] || {}, source[key]);
        } else {
          result[key] = source[key];
        }
      }
      
      return result;
    }

    camelToKebab(str) {
      return str.replace(/([a-z0-9]|(?=[A-Z]))([A-Z])/g, '$1-$2').toLowerCase();
    }

    emitThemeChange(themeId, theme) {
      const event = new CustomEvent('chatbot:theme:changed', {
        detail: { themeId, theme }
      });
      window.dispatchEvent(event);
    }

    // =================================================================
    // 🎨 CSS GENERATION
    // =================================================================

    generateThemeCSS(themeId) {
      const theme = this.themes.get(themeId);
      if (!theme) return '';

      let css = `/* Generated CSS for theme: ${themeId} */\n`;
      css += `[data-theme="${themeId}"] {\n`;
      
      // Colors
      if (theme.colors.primary) {
        Object.entries(theme.colors.primary).forEach(([shade, color]) => {
          css += `  --chatbot-primary-${shade}: ${color};\n`;
        });
      }
      
      if (theme.colors.secondary) {
        Object.entries(theme.colors.secondary).forEach(([shade, color]) => {
          css += `  --chatbot-secondary-${shade}: ${color};\n`;
        });
      }
      
      // Typography
      if (theme.typography.fontFamily.sans) {
        css += `  --chatbot-font-sans: ${theme.typography.fontFamily.sans};\n`;
      }
      
      // Layout
      if (theme.layout.widget) {
        Object.entries(theme.layout.widget).forEach(([prop, value]) => {
          css += `  --chatbot-widget-${this.camelToKebab(prop)}: ${value};\n`;
        });
      }
      
      css += `}\n`;
      
      // Custom CSS
      if (theme.customCSS) {
        css += `\n/* Custom CSS */\n${theme.customCSS}\n`;
      }
      
      return css;
    }

    downloadThemeCSS(themeId) {
      const css = this.generateThemeCSS(themeId);
      const blob = new Blob([css], { type: 'text/css' });
      const url = URL.createObjectURL(blob);
      
      const a = document.createElement('a');
      a.href = url;
      a.download = `chatbot-theme-${themeId}.css`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      
      URL.revokeObjectURL(url);
    }
  }

  // =================================================================
  // 🎨 THEME BUILDER (Visual Editor)
  // =================================================================

  class ChatbotThemeBuilder {
    constructor(themeEngine) {
      this.themeEngine = themeEngine;
      this.currentTheme = null;
      this.previewElement = null;
    }

    createEditor(containerElement) {
      const editor = document.createElement('div');
      editor.className = 'chatbot-theme-editor';
      editor.innerHTML = this.getEditorHTML();
      
      containerElement.appendChild(editor);
      this.setupEditorEvents(editor);
      
      return editor;
    }

    getEditorHTML() {
      return `
        <div class="theme-editor-panel">
          <h3>Theme Editor</h3>
          
          <div class="theme-section">
            <h4>Colors</h4>
            <label>
              Primary Color:
              <input type="color" id="primary-color" value="#3b82f6">
            </label>
            <label>
              Secondary Color:
              <input type="color" id="secondary-color" value="#64748b">
            </label>
          </div>
          
          <div class="theme-section">
            <h4>Typography</h4>
            <label>
              Font Family:
              <select id="font-family">
                <option value="Inter">Inter</option>
                <option value="Roboto">Roboto</option>
                <option value="Open Sans">Open Sans</option>
                <option value="Poppins">Poppins</option>
              </select>
            </label>
          </div>
          
          <div class="theme-section">
            <h4>Layout</h4>
            <label>
              Widget Width:
              <input type="range" id="widget-width" min="300" max="500" value="380">
              <span id="width-value">380px</span>
            </label>
            <label>
              Border Radius:
              <input type="range" id="border-radius" min="0" max="50" value="24">
              <span id="radius-value">24px</span>
            </label>
          </div>
          
          <div class="theme-section">
            <h4>Actions</h4>
            <button id="preview-theme">Preview</button>
            <button id="save-theme">Save Theme</button>
            <button id="export-theme">Export CSS</button>
          </div>
        </div>
      `;
    }

    setupEditorEvents(editor) {
      // Color inputs
      editor.getElementById('primary-color')?.addEventListener('input', (e) => {
        this.updatePreview({ 'colors.primary.500': e.target.value });
      });
      
      // Range inputs
      editor.getElementById('widget-width')?.addEventListener('input', (e) => {
        const value = e.target.value + 'px';
        editor.getElementById('width-value').textContent = value;
        this.updatePreview({ 'layout.widget.width': value });
      });
      
      // Save/export actions
      editor.getElementById('save-theme')?.addEventListener('click', () => {
        this.saveCurrentTheme();
      });
      
      editor.getElementById('export-theme')?.addEventListener('click', () => {
        this.exportCurrentTheme();
      });
    }

    updatePreview(changes) {
      // Apply changes to preview
      if (this.previewElement) {
        Object.entries(changes).forEach(([path, value]) => {
          this.setNestedProperty(this.currentTheme, path, value);
        });
        
        this.themeEngine.applyTheme('preview', this.previewElement);
      }
    }

    setNestedProperty(obj, path, value) {
      const keys = path.split('.');
      let current = obj;
      
      for (let i = 0; i < keys.length - 1; i++) {
        if (!(keys[i] in current)) {
          current[keys[i]] = {};
        }
        current = current[keys[i]];
      }
      
      current[keys[keys.length - 1]] = value;
    }

    saveCurrentTheme() {
      const themeId = `custom-${Date.now()}`;
      this.themeEngine.registerTheme(themeId, this.currentTheme);
      alert(`Theme saved as: ${themeId}`);
    }

    exportCurrentTheme() {
      this.themeEngine.downloadThemeCSS('preview');
    }
  }

  // =================================================================
  // 🚀 INITIALIZATION AND EXPORT
  // =================================================================

  // Create global theme engine instance
  const themeEngine = new ChatbotThemeEngine();
  const themeBuilder = new ChatbotThemeBuilder(themeEngine);

  // Auto-apply theme on load
  function initThemeOnce() {
    const widgetElement = document.getElementById('chatbot-widget');
    if (!widgetElement) {
      console.warn('[WidgetTheme] #chatbot-widget element not found');
      return;
    }
    const tenantId = widgetElement.dataset.tenant;
    const themeId = widgetElement.dataset.theme || 'default';
    console.log('[WidgetTheme] Init', { tenantId, themeId, readyState: document.readyState });

    if (tenantId && tenantId !== 'demo') {
      // Load tenant-specific theme
      themeEngine.loadTenantTheme(tenantId).then(loadedThemeId => {
        console.log('[WidgetTheme] Loaded theme id', loadedThemeId);
        themeEngine.applyTheme(loadedThemeId, widgetElement);
      });
    } else {
      // Apply specified or default theme
      console.log('[WidgetTheme] Applying static theme id', themeId);
      themeEngine.applyTheme(themeId, widgetElement);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initThemeOnce);
  } else {
    // DOM già pronto: inizializza subito
    initThemeOnce();
  }

  // Ascolta quando il widget DOM è pronto dall'embedder
  window.addEventListener('chatbot:widget:ready', function(e) {
    const widgetElement = document.getElementById('chatbot-widget');
    if (!widgetElement) {
      console.warn('[WidgetTheme] widget:ready ma #chatbot-widget non esiste');
      return;
    }
    const { tenantId, theme } = (e && e.detail) ? e.detail : { tenantId: widgetElement.dataset.tenant, theme: widgetElement.dataset.theme };
    console.log('[WidgetTheme] widget:ready received', { tenantId, theme });
    if (tenantId && tenantId !== 'demo') {
      Promise.resolve(themeEngine.loadTenantTheme(tenantId)).then(loadedThemeId => {
        themeEngine.applyTheme(loadedThemeId, widgetElement);
      });
    } else {
      themeEngine.applyTheme(theme || 'default', widgetElement);
    }
  });

  // Global exports
  window.ChatbotThemeEngine = themeEngine;
  window.ChatbotThemeBuilder = themeBuilder;

})();
