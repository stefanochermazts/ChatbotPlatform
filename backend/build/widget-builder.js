#!/usr/bin/env node

/**
 * 🏗️ Chatbot Widget - Build System
 * 
 * Sistema di build per generazione asset widget personalizzati:
 * - Minificazione CSS/JS per performance
 * - Bundle specifici per tenant
 * - Cache busting con hash contenuto
 * - Asset optimization (images, fonts)
 * - CDN-ready deployment
 * - Performance monitoring
 * 
 * @version 1.0.0
 * @author Chatbot Platform
 */

const fs = require('fs').promises;
const path = require('path');
const crypto = require('crypto');
const { minify } = require('terser');
const CleanCSS = require('clean-css');

// =================================================================
// 🛠️ BUILD CONFIGURATION
// =================================================================

const BUILD_CONFIG = {
  // Input directories
  source: {
    css: path.join(__dirname, '../public/widget/css'),
    js: path.join(__dirname, '../public/widget/js'),
    assets: path.join(__dirname, '../public/widget/assets')
  },
  
  // Output directories
  output: {
    base: path.join(__dirname, '../public/widget/dist'),
    tenant: path.join(__dirname, '../public/widget/dist/tenants'),
    cdn: path.join(__dirname, '../public/widget/dist/cdn')
  },
  
  // File configurations
  files: {
    css: [
      'chatbot-design-system.css',
      'chatbot-widget.css',
      'chatbot-accessibility.css',
      'chatbot-citations.css',
      'chatbot-responsive.css',
      'chatbot-dark-mode.css',
      'chatbot-error-handling.css'
    ],
    js: [
      'chatbot-accessibility.js',
      'chatbot-citations.js',
      'chatbot-responsive.js',
      'chatbot-dark-mode.js',
      'chatbot-error-handling.js',
      'chatbot-widget.js',
      'chatbot-theming.js'
    ]
  },
  
  // Build options
  options: {
    minify: true,
    sourceMaps: false, // Disable for production
    cacheHashing: true,
    bundleAnalysis: true,
    gzipCompression: true
  },
  
  // Tenant customization templates
  templates: {
    css: path.join(__dirname, 'templates/tenant-custom.css'),
    config: path.join(__dirname, 'templates/tenant-config.js')
  }
};

// =================================================================
// 🏗️ WIDGET BUILDER CLASS
// =================================================================

class WidgetBuilder {
  constructor(config = BUILD_CONFIG) {
    this.config = config;
    this.buildInfo = {
      timestamp: Date.now(),
      version: '1.0.0',
      assets: {},
      bundles: {},
      stats: {}
    };
  }

  // =================================================================
  // 🚀 MAIN BUILD PROCESS
  // =================================================================

  async build(options = {}) {
    console.log('🏗️  Starting Chatbot Widget Build...\n');
    
    try {
      // Clean output directories
      await this.cleanOutput();
      
      // Create output directories
      await this.createDirectories();
      
      // Build core bundles
      const coreBundles = await this.buildCoreBundles();
      
      // Build tenant-specific assets
      const tenantAssets = await this.buildTenantAssets(options.tenants);
      
      // Copy static assets
      await this.copyStaticAssets();
      
      // Generate manifest
      await this.generateManifest();
      
      // Generate performance report
      const perfReport = await this.generatePerformanceReport();
      
      // Generate CDN assets
      if (options.cdn) {
        await this.generateCDNAssets();
      }
      
      console.log('✅ Build completed successfully!\n');
      
      return {
        bundles: coreBundles,
        tenants: tenantAssets,
        performance: perfReport,
        buildInfo: this.buildInfo
      };
      
    } catch (error) {
      console.error('❌ Build failed:', error.message);
      throw error;
    }
  }

  // =================================================================
  // 🧹 CLEANUP & SETUP
  // =================================================================

  async cleanOutput() {
    console.log('🧹 Cleaning output directories...');
    
    try {
      await fs.rm(this.config.output.base, { recursive: true, force: true });
    } catch (error) {
      // Directory might not exist, that's ok
    }
  }

  async createDirectories() {
    const dirs = [
      this.config.output.base,
      this.config.output.tenant,
      this.config.output.cdn,
      path.join(this.config.output.base, 'css'),
      path.join(this.config.output.base, 'js'),
      path.join(this.config.output.base, 'assets')
    ];
    
    for (const dir of dirs) {
      await fs.mkdir(dir, { recursive: true });
    }
  }

  // =================================================================
  // 📦 CORE BUNDLE BUILD
  // =================================================================

  async buildCoreBundles() {
    console.log('📦 Building core bundles...');
    
    const bundles = {
      css: await this.buildCSSBundle(),
      js: await this.buildJSBundle()
    };
    
    return bundles;
  }

  async buildCSSBundle() {
    console.log('  📄 Processing CSS files...');
    
    let combinedCSS = '';
    const sourceFiles = [];
    
    // Combine all CSS files
    for (const fileName of this.config.files.css) {
      const filePath = path.join(this.config.source.css, fileName);
      try {
        const content = await fs.readFile(filePath, 'utf8');
        combinedCSS += `/* ${fileName} */\n${content}\n\n`;
        sourceFiles.push(fileName);
      } catch (error) {
        console.warn(`    ⚠️  Warning: Could not read ${fileName}`);
      }
    }
    
    // Process and optimize CSS
    const processed = await this.processCSS(combinedCSS);
    
    // Generate filename with hash
    const hash = this.generateHash(processed.css);
    const fileName = `chatbot-widget.${hash}.css`;
    const outputPath = path.join(this.config.output.base, 'css', fileName);
    
    // Write to file
    await fs.writeFile(outputPath, processed.css);
    
    // Store build info
    this.buildInfo.bundles.css = {
      fileName,
      hash,
      size: processed.css.length,
      gzipSize: processed.gzipSize,
      sourceFiles,
      path: `/widget/dist/css/${fileName}`
    };
    
    console.log(`    ✅ CSS bundle: ${fileName} (${this.formatBytes(processed.css.length)})`);
    
    return this.buildInfo.bundles.css;
  }

  async buildJSBundle() {
    console.log('  📄 Processing JavaScript files...');
    
    let combinedJS = '';
    const sourceFiles = [];
    
    // Combine all JS files in correct order
    for (const fileName of this.config.files.js) {
      const filePath = path.join(this.config.source.js, fileName);
      try {
        const content = await fs.readFile(filePath, 'utf8');
        combinedJS += `/* ${fileName} */\n${content}\n\n`;
        sourceFiles.push(fileName);
      } catch (error) {
        console.warn(`    ⚠️  Warning: Could not read ${fileName}`);
      }
    }
    
    // Process and optimize JavaScript
    const processed = await this.processJS(combinedJS);
    
    // Generate filename with hash
    const hash = this.generateHash(processed.js);
    const fileName = `chatbot-widget.${hash}.js`;
    const outputPath = path.join(this.config.output.base, 'js', fileName);
    
    // Write to file
    await fs.writeFile(outputPath, processed.js);
    
    // Store build info
    this.buildInfo.bundles.js = {
      fileName,
      hash,
      size: processed.js.length,
      gzipSize: processed.gzipSize,
      sourceFiles,
      path: `/widget/dist/js/${fileName}`
    };
    
    console.log(`    ✅ JS bundle: ${fileName} (${this.formatBytes(processed.js.length)})`);
    
    return this.buildInfo.bundles.js;
  }

  // =================================================================
  // 🎨 TENANT-SPECIFIC BUILDS
  // =================================================================

  async buildTenantAssets(tenants = []) {
    if (!tenants.length) {
      console.log('📋 No tenants specified, skipping tenant builds...');
      return {};
    }
    
    console.log(`🎨 Building assets for ${tenants.length} tenants...`);
    
    const tenantAssets = {};
    
    for (const tenant of tenants) {
      console.log(`  🏢 Processing tenant: ${tenant.name || tenant.id}`);
      tenantAssets[tenant.id] = await this.buildTenantBundle(tenant);
    }
    
    return tenantAssets;
  }

  async buildTenantBundle(tenant) {
    const tenantDir = path.join(this.config.output.tenant, tenant.id.toString());
    await fs.mkdir(tenantDir, { recursive: true });
    
    // Generate custom CSS with tenant branding
    const customCSS = await this.generateTenantCSS(tenant);
    const customConfig = await this.generateTenantConfig(tenant);
    
    // Write tenant-specific files
    const cssPath = path.join(tenantDir, 'custom.css');
    const configPath = path.join(tenantDir, 'config.js');
    const embedPath = path.join(tenantDir, 'embed.js');
    
    await fs.writeFile(cssPath, customCSS);
    await fs.writeFile(configPath, customConfig);
    await fs.writeFile(embedPath, await this.generateTenantEmbed(tenant));
    
    // Generate asset info
    const assetInfo = {
      id: tenant.id,
      name: tenant.name,
      css: {
        path: `/widget/dist/tenants/${tenant.id}/custom.css`,
        size: customCSS.length
      },
      config: {
        path: `/widget/dist/tenants/${tenant.id}/config.js`,
        size: customConfig.length
      },
      embed: {
        path: `/widget/dist/tenants/${tenant.id}/embed.js`,
        size: (await fs.readFile(embedPath)).length
      },
      theme: tenant.theme || {},
      buildTime: Date.now()
    };
    
    console.log(`    ✅ Tenant ${tenant.id}: ${this.formatBytes(
      assetInfo.css.size + assetInfo.config.size + assetInfo.embed.size
    )}`);
    
    return assetInfo;
  }

  async generateTenantCSS(tenant) {
    const theme = tenant.theme || {};
    
    // Generate CSS custom properties for tenant theme
    const customProperties = Object.entries(theme)
      .map(([key, value]) => `  --chatbot-${key}: ${value};`)
      .join('\n');
    
    return `
/**
 * 🎨 Custom Theme for ${tenant.name || tenant.id}
 * Generated: ${new Date().toISOString()}
 */

:root {
${customProperties}
}

/* Tenant-specific overrides */
.chatbot-widget[data-tenant="${tenant.id}"] {
  ${theme.customCSS || '/* No custom CSS */'} 
}

/* Logo customization */
${theme.logoUrl ? `
.chatbot-widget[data-tenant="${tenant.id}"] .chatbot-logo {
  background-image: url('${theme.logoUrl}');
}
` : ''}

/* Font customization */
${theme.fontFamily ? `
.chatbot-widget[data-tenant="${tenant.id}"] {
  font-family: ${theme.fontFamily}, var(--chatbot-font-family);
}
` : ''}
`.trim();
  }

  async generateTenantConfig(tenant) {
    const config = {
      tenantId: tenant.id,
      tenantName: tenant.name,
      apiKey: tenant.api_key,
      baseURL: tenant.base_url || '',
      theme: tenant.theme || {},
      features: tenant.features || {},
      buildTime: Date.now(),
      version: this.buildInfo.version
    };
    
    return `
/**
 * 🏢 Tenant Configuration for ${tenant.name || tenant.id}
 * Generated: ${new Date().toISOString()}
 */

window.ChatbotTenantConfig = ${JSON.stringify(config, null, 2)};
`.trim();
  }

  async generateTenantEmbed(tenant) {
    const coreBundles = this.buildInfo.bundles;
    
    return `
/**
 * 📦 Tenant Embed Script for ${tenant.name || tenant.id}
 * Generated: ${new Date().toISOString()}
 */

(function() {
  'use strict';
  
  const TENANT_CONFIG = {
    id: '${tenant.id}',
    name: '${tenant.name || ''}',
    baseURL: '${tenant.base_url || ''}',
    bundles: {
      css: '${coreBundles.css?.path || ''}',
      js: '${coreBundles.js?.path || ''}'
    },
    custom: {
      css: '/widget/dist/tenants/${tenant.id}/custom.css',
      config: '/widget/dist/tenants/${tenant.id}/config.js'
    }
  };
  
  // Load core CSS bundle
  const cssLink = document.createElement('link');
  cssLink.rel = 'stylesheet';
  cssLink.href = TENANT_CONFIG.bundles.css;
  document.head.appendChild(cssLink);
  
  // Load tenant custom CSS
  const customCSSLink = document.createElement('link');
  customCSSLink.rel = 'stylesheet';
  customCSSLink.href = TENANT_CONFIG.custom.css;
  document.head.appendChild(customCSSLink);
  
  // Load tenant config
  const configScript = document.createElement('script');
  configScript.src = TENANT_CONFIG.custom.config;
  configScript.onload = function() {
    // Load core JS bundle
    const jsScript = document.createElement('script');
    jsScript.src = TENANT_CONFIG.bundles.js;
    jsScript.onload = function() {
      // Initialize widget with tenant config
      if (window.ChatbotWidget && window.ChatbotTenantConfig) {
        window.chatbotWidget = new window.ChatbotWidget(window.ChatbotTenantConfig);
      }
    };
    document.head.appendChild(jsScript);
  };
  document.head.appendChild(configScript);
  
})();
`.trim();
  }

  // =================================================================
  // ⚡ CSS/JS PROCESSING
  // =================================================================

  async processCSS(css) {
    let processed = css;
    let gzipSize = 0;
    
    if (this.config.options.minify) {
      const cleanCSS = new CleanCSS({
        level: 2,
        returnPromise: true
      });
      
      const result = await cleanCSS.minify(processed);
      processed = result.styles;
      
      if (result.errors.length > 0) {
        console.warn('    ⚠️  CSS minification warnings:', result.errors);
      }
    }
    
    // Calculate gzip size
    if (this.config.options.gzipCompression) {
      const zlib = require('zlib');
      gzipSize = zlib.gzipSync(processed).length;
    }
    
    return {
      css: processed,
      originalSize: css.length,
      minifiedSize: processed.length,
      gzipSize,
      compression: ((css.length - processed.length) / css.length * 100).toFixed(1)
    };
  }

  async processJS(js) {
    let processed = js;
    let gzipSize = 0;
    
    if (this.config.options.minify) {
      const result = await minify(processed, {
        compress: {
          drop_console: true,
          drop_debugger: true,
          pure_funcs: ['console.log', 'console.info', 'console.debug']
        },
        mangle: {
          reserved: ['ChatbotWidget', 'ChatbotAccessibilityManager', 'ChatbotCitationsManager']
        },
        format: {
          comments: false
        }
      });
      
      processed = result.code;
      
      if (result.warnings) {
        console.warn('    ⚠️  JS minification warnings:', result.warnings);
      }
    }
    
    // Calculate gzip size
    if (this.config.options.gzipCompression) {
      const zlib = require('zlib');
      gzipSize = zlib.gzipSync(processed).length;
    }
    
    return {
      js: processed,
      originalSize: js.length,
      minifiedSize: processed.length,
      gzipSize,
      compression: ((js.length - processed.length) / js.length * 100).toFixed(1)
    };
  }

  // =================================================================
  // 📁 STATIC ASSETS
  // =================================================================

  async copyStaticAssets() {
    console.log('📁 Copying static assets...');
    
    const assetsDir = this.config.source.assets;
    
    try {
      const files = await fs.readdir(assetsDir);
      
      for (const file of files) {
        const sourcePath = path.join(assetsDir, file);
        const targetPath = path.join(this.config.output.base, 'assets', file);
        
        await fs.copyFile(sourcePath, targetPath);
      }
      
      console.log(`    ✅ Copied ${files.length} static assets`);
    } catch (error) {
      console.log(`    ℹ️  No static assets found (${assetsDir})`);
    }
  }

  // =================================================================
  // 📋 MANIFEST & REPORTS
  // =================================================================

  async generateManifest() {
    console.log('📋 Generating build manifest...');
    
    const manifest = {
      version: this.buildInfo.version,
      buildTime: this.buildInfo.timestamp,
      buildDate: new Date(this.buildInfo.timestamp).toISOString(),
      bundles: this.buildInfo.bundles,
      assets: this.buildInfo.assets,
      stats: this.buildInfo.stats
    };
    
    const manifestPath = path.join(this.config.output.base, 'manifest.json');
    await fs.writeFile(manifestPath, JSON.stringify(manifest, null, 2));
    
    console.log('    ✅ Manifest generated');
  }

  async generatePerformanceReport() {
    console.log('📊 Generating performance report...');
    
    const bundles = this.buildInfo.bundles;
    const totalSize = (bundles.css?.size || 0) + (bundles.js?.size || 0);
    const totalGzipSize = (bundles.css?.gzipSize || 0) + (bundles.js?.gzipSize || 0);
    
    const report = {
      bundleSize: {
        total: totalSize,
        totalGzip: totalGzipSize,
        css: bundles.css?.size || 0,
        js: bundles.js?.size || 0,
        cssGzip: bundles.css?.gzipSize || 0,
        jsGzip: bundles.js?.gzipSize || 0
      },
      performance: {
        loadTime: this.estimateLoadTime(totalGzipSize),
        mobile3G: this.estimateLoadTime(totalGzipSize, 1.6), // 1.6 Mbps
        mobile4G: this.estimateLoadTime(totalGzipSize, 10),  // 10 Mbps
        broadband: this.estimateLoadTime(totalGzipSize, 25)  // 25 Mbps
      },
      recommendations: this.generateRecommendations(totalSize, totalGzipSize)
    };
    
    // Write performance report
    const reportPath = path.join(this.config.output.base, 'performance-report.json');
    await fs.writeFile(reportPath, JSON.stringify(report, null, 2));
    
    // Console output
    console.log(`    📦 Total bundle size: ${this.formatBytes(totalSize)}`);
    console.log(`    🗜️  Gzipped size: ${this.formatBytes(totalGzipSize)}`);
    console.log(`    ⚡ Estimated load time (3G): ${report.performance.mobile3G}ms`);
    
    return report;
  }

  generateRecommendations(totalSize, gzipSize) {
    const recommendations = [];
    
    if (gzipSize > 100 * 1024) { // > 100KB
      recommendations.push({
        type: 'warning',
        message: 'Bundle size is large. Consider code splitting or lazy loading.',
        priority: 'high'
      });
    }
    
    if (gzipSize > 50 * 1024) { // > 50KB
      recommendations.push({
        type: 'info',
        message: 'Consider enabling HTTP/2 server push for faster loading.',
        priority: 'medium'
      });
    }
    
    if (totalSize / gzipSize < 3) { // Low compression ratio
      recommendations.push({
        type: 'info',
        message: 'Low compression ratio. Check for already compressed assets.',
        priority: 'low'
      });
    }
    
    return recommendations;
  }

  // =================================================================
  // 🌐 CDN ASSETS
  // =================================================================

  async generateCDNAssets() {
    console.log('🌐 Generating CDN-optimized assets...');
    
    const cdnDir = this.config.output.cdn;
    
    // Copy core bundles with CDN-friendly names
    const bundles = this.buildInfo.bundles;
    
    if (bundles.css) {
      const cdnCSSPath = path.join(cdnDir, `chatbot-widget-v${this.buildInfo.version}.css`);
      const sourcePath = path.join(this.config.output.base, 'css', bundles.css.fileName);
      await fs.copyFile(sourcePath, cdnCSSPath);
    }
    
    if (bundles.js) {
      const cdnJSPath = path.join(cdnDir, `chatbot-widget-v${this.buildInfo.version}.js`);
      const sourcePath = path.join(this.config.output.base, 'js', bundles.js.fileName);
      await fs.copyFile(sourcePath, cdnJSPath);
    }
    
    // Generate CDN manifest
    const cdnManifest = {
      version: this.buildInfo.version,
      assets: {
        css: `/widget/dist/cdn/chatbot-widget-v${this.buildInfo.version}.css`,
        js: `/widget/dist/cdn/chatbot-widget-v${this.buildInfo.version}.js`
      },
      integrity: {
        css: await this.generateSRI(path.join(cdnDir, `chatbot-widget-v${this.buildInfo.version}.css`)),
        js: await this.generateSRI(path.join(cdnDir, `chatbot-widget-v${this.buildInfo.version}.js`))
      }
    };
    
    await fs.writeFile(
      path.join(cdnDir, 'manifest.json'),
      JSON.stringify(cdnManifest, null, 2)
    );
    
    console.log('    ✅ CDN assets generated with SRI hashes');
  }

  // =================================================================
  // 🔧 UTILITY METHODS
  // =================================================================

  generateHash(content) {
    return crypto.createHash('sha256').update(content).digest('hex').substring(0, 8);
  }

  async generateSRI(filePath) {
    const content = await fs.readFile(filePath);
    const hash = crypto.createHash('sha384').update(content).digest('base64');
    return `sha384-${hash}`;
  }

  formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  estimateLoadTime(sizeBytes, speedMbps = 5) {
    const sizeBits = sizeBytes * 8;
    const speedBps = speedMbps * 1000000;
    return Math.ceil((sizeBits / speedBps) * 1000); // milliseconds
  }
}

// =================================================================
// 🚀 CLI INTERFACE
// =================================================================

async function main() {
  const args = process.argv.slice(2);
  const options = {
    tenants: [],
    cdn: false,
    watch: false
  };
  
  // Parse command line arguments
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    
    switch (arg) {
      case '--tenants':
        if (args[i + 1] && !args[i + 1].startsWith('--')) {
          try {
            options.tenants = JSON.parse(args[i + 1]);
            i++;
          } catch (error) {
            console.error('❌ Invalid JSON for --tenants argument');
            process.exit(1);
          }
        }
        break;
        
      case '--cdn':
        options.cdn = true;
        break;
        
      case '--watch':
        options.watch = true;
        break;
        
      case '--help':
        console.log(`
🏗️  Chatbot Widget Build System

Usage: node widget-builder.js [options]

Options:
  --tenants <json>    JSON array of tenant configurations
  --cdn               Generate CDN-optimized assets
  --watch             Watch for file changes (dev mode)
  --help              Show this help message

Examples:
  node widget-builder.js
  node widget-builder.js --cdn
  node widget-builder.js --tenants '[{"id":1,"name":"Acme","theme":{"primary":"#ff0000"}}]'
        `);
        process.exit(0);
    }
  }
  
  const builder = new WidgetBuilder();
  
  if (options.watch) {
    console.log('👀 Watch mode not implemented yet. Use regular build for now.');
    process.exit(1);
  }
  
  try {
    const result = await builder.build(options);
    
    console.log('\n📊 Build Summary:');
    console.log(`   Core CSS: ${builder.formatBytes(result.bundles.css.size)}`);
    console.log(`   Core JS:  ${builder.formatBytes(result.bundles.js.size)}`);
    console.log(`   Tenants:  ${Object.keys(result.tenants).length}`);
    console.log(`   Total:    ${builder.formatBytes(
      result.bundles.css.size + result.bundles.js.size
    )}`);
    
    process.exit(0);
  } catch (error) {
    console.error('\n❌ Build failed:', error.message);
    process.exit(1);
  }
}

// Run if called directly
if (require.main === module) {
  main().catch(console.error);
}

module.exports = { WidgetBuilder, BUILD_CONFIG };
