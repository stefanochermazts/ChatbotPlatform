/**
 * 📱 Chatbot Widget - Responsive Manager
 * 
 * Gestisce il comportamento responsive del widget:
 * - Rilevamento tipo dispositivo e capabilities
 * - Gestione cambio orientamento
 * - Posizionamento adattivo
 * - Ottimizzazioni performance per mobile
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

(function() {
  'use strict';

  // =================================================================
  // 📱 RESPONSIVE MANAGER CLASS
  // =================================================================

  class ResponsiveManager {
    constructor(chatbotInstance) {
      this.chatbot = chatbotInstance;
      this.device = this.detectDevice();
      this.viewport = this.getViewportInfo();
      this.isTouch = this.detectTouchCapability();
      this.reducedMotion = this.detectReducedMotion();
      
      // State tracking
      this.lastOrientation = null;
      this.lastViewportWidth = null;
      this.lastViewportHeight = null;
      
      // Throttle timers
      this.resizeTimer = null;
      this.orientationTimer = null;
      
      this.init();
    }

    init() {
      this.setupEventListeners();
      this.applyDeviceOptimizations();
      this.updateViewportClasses();
      this.handleInitialLayout();
    }

    // =================================================================
    // 🔍 DEVICE DETECTION
    // =================================================================

    detectDevice() {
      const userAgent = navigator.userAgent;
      const platform = navigator.platform;
      const maxTouchPoints = navigator.maxTouchPoints || 0;
      
      // Screen dimensions
      const screenWidth = screen.width;
      const screenHeight = screen.height;
      const viewportWidth = window.innerWidth;
      const viewportHeight = window.innerHeight;
      
      // Device type detection
      let deviceType = 'desktop';
      let os = 'unknown';
      
      // OS Detection
      if (/iPad|iPhone|iPod/.test(userAgent)) {
        os = 'ios';
        deviceType = /iPad/.test(userAgent) ? 'tablet' : 'mobile';
      } else if (/Android/.test(userAgent)) {
        os = 'android';
        deviceType = maxTouchPoints > 1 && screenWidth >= 768 ? 'tablet' : 'mobile';
      } else if (/Windows Phone/.test(userAgent)) {
        os = 'windows_phone';
        deviceType = 'mobile';
      } else if (/Mac|MacIntel/.test(platform)) {
        os = 'macos';
        deviceType = maxTouchPoints > 1 ? 'tablet' : 'desktop';
      } else if (/Win/.test(platform)) {
        os = 'windows';
        deviceType = maxTouchPoints > 1 && screenWidth < 1024 ? 'tablet' : 'desktop';
      } else if (/Linux/.test(platform)) {
        os = 'linux';
      }

      // Screen size based classification
      if (viewportWidth < 480) {
        deviceType = 'mobile';
      } else if (viewportWidth < 768) {
        deviceType = 'mobile-large';
      } else if (viewportWidth < 1024) {
        deviceType = 'tablet';
      }

      return {
        type: deviceType,
        os: os,
        userAgent: userAgent,
        platform: platform,
        screenWidth: screenWidth,
        screenHeight: screenHeight,
        hasNotch: this.detectNotch(),
        pixelRatio: window.devicePixelRatio || 1,
        maxTouchPoints: maxTouchPoints
      };
    }

    detectNotch() {
      // Detection for device notches (iPhone X, etc.)
      if (typeof window !== 'undefined' && window.CSS && window.CSS.supports) {
        return window.CSS.supports('padding-top: env(safe-area-inset-top)');
      }
      return false;
    }

    detectTouchCapability() {
      return (
        'ontouchstart' in window ||
        navigator.maxTouchPoints > 0 ||
        navigator.msMaxTouchPoints > 0
      );
    }

    detectReducedMotion() {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    getViewportInfo() {
      return {
        width: window.innerWidth,
        height: window.innerHeight,
        orientation: this.getOrientation(),
        aspectRatio: window.innerWidth / window.innerHeight
      };
    }

    getOrientation() {
      if (screen.orientation) {
        return screen.orientation.angle === 0 || screen.orientation.angle === 180 
          ? 'portrait' : 'landscape';
      }
      return window.innerHeight > window.innerWidth ? 'portrait' : 'landscape';
    }

    // =================================================================
    // 📡 EVENT LISTENERS
    // =================================================================

    setupEventListeners() {
      // Viewport resize
      window.addEventListener('resize', this.throttle(() => {
        this.handleResize();
      }, 250));

      // Orientation change
      window.addEventListener('orientationchange', () => {
        // Small delay to get accurate dimensions after orientation change
        setTimeout(() => {
          this.handleOrientationChange();
        }, 100);
      });

      // Viewport change (mobile browsers)
      window.addEventListener('scroll', this.throttle(() => {
        this.handleViewportChange();
      }, 100));

      // Touch capability changes (hybrid devices)
      if ('ontouchstart' in window) {
        document.addEventListener('touchstart', this.handleFirstTouch.bind(this), { 
          once: true, 
          passive: true 
        });
      }

      // Media query listeners
      this.setupMediaQueryListeners();
    }

    setupMediaQueryListeners() {
      // Reduced motion preference
      const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
      reducedMotionQuery.addListener((e) => {
        this.reducedMotion = e.matches;
        this.updateMotionPreferences();
      });

      // Color scheme preference
      const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
      darkModeQuery.addListener((e) => {
        this.handleColorSchemeChange(e.matches);
      });

      // Hover capability
      const hoverQuery = window.matchMedia('(hover: hover)');
      hoverQuery.addListener((e) => {
        this.updateHoverCapability(e.matches);
      });
    }

    // =================================================================
    // 🎯 EVENT HANDLERS
    // =================================================================

    handleResize() {
      const newViewport = this.getViewportInfo();
      const widthChanged = newViewport.width !== this.lastViewportWidth;
      const heightChanged = newViewport.height !== this.lastViewportHeight;

      if (widthChanged || heightChanged) {
        this.viewport = newViewport;
        this.updateViewportClasses();
        this.adjustLayoutForViewport();
        
        // Update device type if screen size changed significantly
        if (widthChanged) {
          const oldDeviceType = this.device.type;
          this.device = this.detectDevice();
          
          if (oldDeviceType !== this.device.type) {
            this.applyDeviceOptimizations();
          }
        }

        this.lastViewportWidth = newViewport.width;
        this.lastViewportHeight = newViewport.height;
      }
    }

    handleOrientationChange() {
      const newOrientation = this.getOrientation();
      
      if (newOrientation !== this.lastOrientation) {
        this.viewport.orientation = newOrientation;
        this.updateOrientationClasses();
        this.adjustLayoutForOrientation();
        
        // Recalculate viewport after orientation change
        setTimeout(() => {
          this.viewport = this.getViewportInfo();
          this.adjustLayoutForViewport();
        }, 300);

        this.lastOrientation = newOrientation;
      }
    }

    handleViewportChange() {
      // Handle dynamic viewport changes on mobile (keyboard, etc.)
      const currentHeight = window.innerHeight;
      const viewportHeightChanged = Math.abs(currentHeight - this.viewport.height) > 50;

      if (viewportHeightChanged) {
        this.viewport.height = currentHeight;
        this.adjustForKeyboard();
      }
    }

    handleFirstTouch() {
      this.isTouch = true;
      this.updateTouchClasses();
    }

    handleColorSchemeChange(isDark) {
      const container = document.getElementById('chatbot-container');
      if (container) {
        container.classList.toggle('dark-mode', isDark);
      }
    }

    // =================================================================
    // 🎨 LAYOUT ADJUSTMENTS
    // =================================================================

    updateViewportClasses() {
      const container = document.getElementById('chatbot-container');
      const fab = document.getElementById('chatbot-fab');
      
      if (!container) return;

      // Remove existing viewport classes
      container.classList.remove(
        'viewport-mobile', 'viewport-mobile-large', 'viewport-tablet', 
        'viewport-desktop', 'viewport-wide'
      );

      // Add current viewport class
      const viewportClass = this.getViewportClass();
      container.classList.add(viewportClass);
      
      if (fab) {
        fab.classList.remove(
          'viewport-mobile', 'viewport-mobile-large', 'viewport-tablet', 
          'viewport-desktop', 'viewport-wide'
        );
        fab.classList.add(viewportClass);
      }
    }

    updateOrientationClasses() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      container.classList.remove('orientation-portrait', 'orientation-landscape');
      container.classList.add(`orientation-${this.viewport.orientation}`);
    }

    updateTouchClasses() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      container.classList.toggle('touch-device', this.isTouch);
      container.classList.toggle('no-touch', !this.isTouch);
    }

    updateHoverCapability(hasHover) {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      container.classList.toggle('has-hover', hasHover);
      container.classList.toggle('no-hover', !hasHover);
    }

    updateMotionPreferences() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      container.classList.toggle('reduced-motion', this.reducedMotion);
    }

    getViewportClass() {
      const width = this.viewport.width;
      
      if (width < 480) return 'viewport-mobile';
      if (width < 768) return 'viewport-mobile-large';
      if (width < 1024) return 'viewport-tablet';
      if (width < 1280) return 'viewport-desktop';
      return 'viewport-wide';
    }

    adjustLayoutForViewport() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      // Adjust container positioning for different viewport sizes
      if (this.device.type === 'mobile') {
        this.setMobileLayout(container);
      } else if (this.device.type === 'tablet') {
        this.setTabletLayout(container);
      } else {
        this.setDesktopLayout(container);
      }
    }

    adjustLayoutForOrientation() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      if (this.viewport.orientation === 'landscape' && this.device.type === 'mobile') {
        // Optimize for landscape mobile
        container.style.maxHeight = '100vh';
        
        // Compact header in landscape
        const header = container.querySelector('.chatbot-header');
        if (header) {
          header.style.minHeight = '40px';
        }
      } else {
        // Reset styles for portrait
        container.style.maxHeight = '';
        
        const header = container.querySelector('.chatbot-header');
        if (header) {
          header.style.minHeight = '';
        }
      }
    }

    adjustForKeyboard() {
      if (!this.isTouch || this.device.type !== 'mobile') return;

      const container = document.getElementById('chatbot-container');
      if (!container) return;

      // Detect if virtual keyboard is open
      const initialViewportHeight = window.screen.height;
      const currentViewportHeight = window.innerHeight;
      const keyboardHeight = initialViewportHeight - currentViewportHeight;
      
      if (keyboardHeight > 150) { // Keyboard likely open
        container.classList.add('keyboard-open');
        
        // Scroll to input when keyboard opens
        const input = document.getElementById('chatbot-input');
        if (input) {
          setTimeout(() => {
            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }, 300);
        }
      } else {
        container.classList.remove('keyboard-open');
      }
    }

    // =================================================================
    // 📐 LAYOUT CONFIGURATIONS
    // =================================================================

    setMobileLayout(container) {
      // Full screen mobile layout
      container.style.width = '100vw';
      container.style.height = '100vh';
      container.style.top = '0';
      container.style.left = '0';
      container.style.right = '0';
      container.style.bottom = '0';
      container.style.borderRadius = '0';
      container.style.maxWidth = '100vw';
      container.style.maxHeight = '100vh';
    }

    setTabletLayout(container) {
      // Floating window on tablet
      container.style.width = '400px';
      container.style.height = '600px';
      container.style.top = '';
      container.style.left = '';
      container.style.right = '16px';
      container.style.bottom = '16px';
      container.style.borderRadius = 'var(--chatbot-border-radius-lg)';
      container.style.maxWidth = 'calc(100vw - 32px)';
      container.style.maxHeight = 'calc(100vh - 32px)';
    }

    setDesktopLayout(container) {
      // Standard desktop floating window
      container.style.width = '380px';
      container.style.height = '500px';
      container.style.top = '';
      container.style.left = '';
      container.style.right = '24px';
      container.style.bottom = '24px';
      container.style.borderRadius = 'var(--chatbot-border-radius-lg)';
      container.style.maxWidth = '420px';
      container.style.maxHeight = '600px';
    }

    applyDeviceOptimizations() {
      const container = document.getElementById('chatbot-container');
      if (!container) return;

      // Add device-specific classes
      container.classList.remove(
        'device-mobile', 'device-tablet', 'device-desktop',
        'os-ios', 'os-android', 'os-windows', 'os-macos', 'os-linux'
      );
      
      container.classList.add(`device-${this.device.type}`);
      container.classList.add(`os-${this.device.os}`);

      // Apply device-specific optimizations
      if (this.device.os === 'ios') {
        this.applyIOSOptimizations(container);
      } else if (this.device.os === 'android') {
        this.applyAndroidOptimizations(container);
      }

      // High DPI optimizations
      if (this.device.pixelRatio > 1.5) {
        container.classList.add('high-dpi');
      }
    }

    applyIOSOptimizations(container) {
      // iOS specific optimizations
      container.classList.add('ios-optimized');
      
      // Safe area handling
      if (this.device.hasNotch) {
        container.style.paddingTop = 'env(safe-area-inset-top)';
        container.style.paddingBottom = 'env(safe-area-inset-bottom)';
      }

      // Prevent zoom on input focus
      const inputs = container.querySelectorAll('input, textarea');
      inputs.forEach(input => {
        if (parseFloat(getComputedStyle(input).fontSize) < 16) {
          input.style.fontSize = '16px';
        }
      });
    }

    applyAndroidOptimizations(container) {
      // Android specific optimizations
      container.classList.add('android-optimized');
      
      // Better scroll behavior
      const messagesContainer = container.querySelector('.chatbot-messages');
      if (messagesContainer) {
        messagesContainer.style.overflowScrolling = 'touch';
        messagesContainer.style.webkitOverflowScrolling = 'touch';
      }
    }

    handleInitialLayout() {
      // Apply initial responsive state
      this.updateViewportClasses();
      this.updateOrientationClasses();
      this.updateTouchClasses();
      this.updateMotionPreferences();
      this.adjustLayoutForViewport();
    }

    // =================================================================
    // 🛠️ UTILITY METHODS
    // =================================================================

    throttle(func, delay) {
      let timeoutId;
      let lastExecTime = 0;
      
      return function (...args) {
        const currentTime = Date.now();
        
        if (currentTime - lastExecTime > delay) {
          func.apply(this, args);
          lastExecTime = currentTime;
        } else {
          clearTimeout(timeoutId);
          timeoutId = setTimeout(() => {
            func.apply(this, args);
            lastExecTime = Date.now();
          }, delay - (currentTime - lastExecTime));
        }
      };
    }

    debounce(func, delay) {
      let timeoutId;
      return function (...args) {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => func.apply(this, args), delay);
      };
    }

    // =================================================================
    // 📱 PUBLIC API
    // =================================================================

    // Get current device info
    getDeviceInfo() {
      return {
        device: this.device,
        viewport: this.viewport,
        isTouch: this.isTouch,
        reducedMotion: this.reducedMotion
      };
    }

    // Force layout update
    updateLayout() {
      this.handleResize();
    }

    // Check if device is mobile
    isMobile() {
      return this.device.type === 'mobile' || this.device.type === 'mobile-large';
    }

    // Check if device is tablet
    isTablet() {
      return this.device.type === 'tablet';
    }

    // Check if device is desktop
    isDesktop() {
      return this.device.type === 'desktop';
    }

    // Clean up event listeners
    destroy() {
      // Remove event listeners if needed
      // (Most are handled automatically by browser)
    }
  }

  // =================================================================
  // 🌐 GLOBAL EXPORT
  // =================================================================

  // Export to global scope for use by chatbot widget
  window.ChatbotResponsiveManager = ResponsiveManager;

})();
