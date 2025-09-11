<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetConfig extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'tenant_id',
        'enabled',
        'widget_name',
        'welcome_message',
        'position',
        'auto_open',
        'theme',
        'custom_colors',
        'logo_url',
        'favicon_url',
        'font_family',
        'widget_width',
        'widget_height',
        'border_radius',
        'button_size',
        'show_header',
        'show_avatar',
        'show_close_button',
        'enable_animations',
        'enable_dark_mode',
        'api_model',
        'temperature',
        'max_tokens',
        'enable_conversation_context',
        'allowed_domains',
        'enable_analytics',
        'gdpr_compliant',
        'custom_css',
        'custom_js',
        'advanced_config',
        'last_updated_at',
        'updated_by'
    ];
    
    protected $casts = [
        'enabled' => 'boolean',
        'auto_open' => 'boolean',
        'custom_colors' => 'array',
        'show_header' => 'boolean',
        'show_avatar' => 'boolean',
        'show_close_button' => 'boolean',
        'enable_animations' => 'boolean',
        'enable_dark_mode' => 'boolean',
        'temperature' => 'decimal:2',
        'max_tokens' => 'integer',
        'enable_conversation_context' => 'boolean',
        'allowed_domains' => 'array',
        'enable_analytics' => 'boolean',
        'gdpr_compliant' => 'boolean',
        'advanced_config' => 'array',
        'last_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    // =================================================================
    // RELATIONSHIPS
    // =================================================================
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    
    // =================================================================
    // ACCESSORS & MUTATORS
    // =================================================================
    
    public function getThemeConfigAttribute(): array
    {
        // Get colors based on theme
        $colors = $this->theme === 'custom' 
            ? ($this->custom_colors ?? [])
            : $this->getPredefinedThemeColors($this->theme);
            
        return [
            'name' => $this->widget_name,
            'theme' => $this->theme,
            'colors' => $colors,
            'brand' => [
                'name' => $this->widget_name,
                'logo' => $this->logo_url,
                'favicon' => $this->favicon_url,
                'companyName' => $this->tenant->name ?? 'La tua azienda'
            ],
            'typography' => [
                'fontFamily' => [
                    'sans' => $this->font_family ?? "'Inter', sans-serif"
                ]
            ],
            'layout' => [
                'widget' => [
                    'width' => $this->widget_width,
                    'height' => $this->widget_height,
                    'borderRadius' => $this->border_radius
                ],
                'button' => [
                    'size' => $this->button_size
                ]
            ],
            'behavior' => [
                'autoOpen' => $this->auto_open,
                'showHeader' => $this->show_header,
                'showAvatar' => $this->show_avatar,
                'showCloseButton' => $this->show_close_button,
                'enableAnimations' => $this->enable_animations,
                'enableDarkMode' => $this->enable_dark_mode
            ],
            'customCSS' => $this->custom_css,
            'advanced' => $this->advanced_config ?? []
        ];
    }
    
    public function getEmbedConfigAttribute(): array
    {
        return [
            // Core API configuration
            'apiKey' => $this->tenant->getWidgetApiKey(),
            'tenantId' => $this->tenant->id,
            
            // Theme and appearance from widget config form
            'theme' => $this->theme,
            'position' => $this->position,
            'autoOpen' => $this->auto_open,
            
            // Layout configuration from form
            'layout' => [
                'widget' => [
                    'width' => $this->widget_width ?? '400px',
                    'height' => $this->widget_height ?? '600px',
                    'borderRadius' => $this->border_radius ?? '12px'
                ],
                'button' => [
                    'size' => $this->button_size ?? '60px'
                ]
            ],
            
            // Behavior settings from form
            'behavior' => [
                'showHeader' => $this->show_header ?? true,
                'showAvatar' => $this->show_avatar ?? true,
                'showCloseButton' => $this->show_close_button ?? true,
                'enableAnimations' => $this->enable_animations ?? true,
                'enableDarkMode' => $this->enable_dark_mode ?? false
            ],
            
            // Branding from form
            'branding' => [
                'logoUrl' => $this->logo_url,
                'faviconUrl' => $this->favicon_url,
                'fontFamily' => $this->font_family ?? "'Inter', sans-serif",
                'customColors' => $this->custom_colors
            ],
            
            // API and conversation settings
            'enableConversationContext' => $this->enable_conversation_context,
            'enableAnalytics' => $this->enable_analytics,
            'model' => $this->api_model,
            'temperature' => $this->temperature,
            'maxTokens' => $this->max_tokens,
            
            // 🔄 CONVERSATION PERSISTENCE: Always enabled for better UX
            'enableConversationPersistence' => true,
            
            // Server-dependent features (will be overridden in preview if needed)
            'enableQuickActions' => $this->advanced_config['enableQuickActions'] ?? true,
            'enableThemeAPI' => $this->advanced_config['enableThemeAPI'] ?? true,
            
            // Widget content and messaging
            'welcomeMessage' => $this->welcome_message,
            'widgetName' => $this->widget_name,
            
            // Security settings
            'allowedDomains' => $this->allowed_domains,
            'gdprCompliant' => $this->gdpr_compliant ?? false,
            
            // Custom styling
            'customCSS' => $this->custom_css,
            'customJS' => $this->custom_js,
            
            // Advanced configuration
            'advanced' => $this->advanced_config ?? []
        ];
    }
    
    // =================================================================
    // SCOPES
    // =================================================================
    
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
    
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    // =================================================================
    // METHODS
    // =================================================================
    
    public function generateEmbedCode(string $baseUrl = null): string
    {
        $baseUrl = $baseUrl ?? config('app.url');
        $config = $this->embed_config;
        
        $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        return "<script>\n" .
               "  window.chatbotConfig = {$configJson};\n" .
               "</script>\n" .
               "<script src=\"{$baseUrl}/widget/embed/chatbot-embed.js\" async></script>";
    }
    
    public function generateThemeCSS(): string
    {
        $theme = $this->theme_config;
        
        $css = "/* Generated theme CSS for tenant: {$this->tenant->name} */\n";
        $css .= "[data-tenant=\"{$this->tenant->slug}\"] {\n";
        
        // Colors
        if (!empty($theme['colors'])) {
            foreach ($theme['colors'] as $colorType => $shades) {
                if (is_array($shades)) {
                    foreach ($shades as $shade => $color) {
                        $css .= "  --chatbot-{$colorType}-{$shade}: {$color};\n";
                    }
                }
            }
        }
        
        // Layout
        if (!empty($theme['layout']['widget'])) {
            foreach ($theme['layout']['widget'] as $prop => $value) {
                $cssProp = $this->camelToKebab($prop);
                $css .= "  --chatbot-widget-{$cssProp}: {$value};\n";
            }
        }
        
        $css .= "}\n";
        
        // Custom CSS
        if ($this->custom_css) {
            $css .= "\n/* Custom CSS */\n{$this->custom_css}\n";
        }
        
        return $css;
    }
    
    public function updateLastModified($userId = null): void
    {
        $this->update([
            'last_updated_at' => now(),
            'updated_by' => $userId
        ]);
    }
    
    private function camelToKebab(string $string): string
    {
        return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $string));
    }
    
    /**
     * Get predefined theme colors
     */
    private function getPredefinedThemeColors(string $theme): array
    {
        $themes = [
            'default' => [
                'primary' => [
                    '50' => '#eff6ff',
                    '100' => '#dbeafe', 
                    '200' => '#bfdbfe',
                    '300' => '#93c5fd',
                    '400' => '#60a5fa',
                    '500' => '#3b82f6', // Main blue
                    '600' => '#2563eb',
                    '700' => '#1d4ed8',
                    '800' => '#1e40af',
                    '900' => '#1e3a8a'
                ]
            ],
            'corporate' => [
                'primary' => [
                    '50' => '#f8fafc',
                    '100' => '#f1f5f9',
                    '200' => '#e2e8f0', 
                    '300' => '#cbd5e1',
                    '400' => '#94a3b8',
                    '500' => '#64748b', // Main gray
                    '600' => '#475569',
                    '700' => '#334155',
                    '800' => '#1e293b',
                    '900' => '#0f172a'
                ]
            ],
            'friendly' => [
                'primary' => [
                    '50' => '#f0fdf4',
                    '100' => '#dcfce7',
                    '200' => '#bbf7d0',
                    '300' => '#86efac',
                    '400' => '#4ade80',
                    '500' => '#22c55e', // Main green
                    '600' => '#16a34a',
                    '700' => '#15803d',
                    '800' => '#166534',
                    '900' => '#14532d'
                ]
            ],
            'high-contrast' => [
                'primary' => [
                    '50' => '#ffffff',
                    '100' => '#f3f4f6',
                    '200' => '#e5e7eb',
                    '300' => '#d1d5db', 
                    '400' => '#9ca3af',
                    '500' => '#000000', // Black for high contrast
                    '600' => '#1f2937',
                    '700' => '#374151',
                    '800' => '#4b5563',
                    '900' => '#6b7280'
                ]
            ]
        ];
        
        return $themes[$theme] ?? $themes['default'];
    }
    
    /**
     * Generate CSS with current design system colors for easy customization
     */
    public function getCurrentColorsCSS(): string
    {
        $css = "/* 🎨 Current Widget Colors - Ready for Customization */\n";
        $css .= "/* Copy and paste this into the Custom CSS field to start customizing */\n\n";
        
        $css .= ":root {\n";
        
        // Primary brand colors
        $css .= "  /* Primary Brand Colors */\n";
        $css .= "  --chatbot-primary-50: #eff6ff;\n";
        $css .= "  --chatbot-primary-100: #dbeafe;\n";
        $css .= "  --chatbot-primary-200: #bfdbfe;\n";
        $css .= "  --chatbot-primary-300: #93c5fd;\n";
        $css .= "  --chatbot-primary-400: #60a5fa;\n";
        $css .= "  --chatbot-primary-500: #3b82f6; /* Main brand color - Change this! */\n";
        $css .= "  --chatbot-primary-600: #2563eb;\n";
        $css .= "  --chatbot-primary-700: #1d4ed8;\n";
        $css .= "  --chatbot-primary-800: #1e40af;\n";
        $css .= "  --chatbot-primary-900: #1e3a8a;\n\n";
        
        // Text colors
        $css .= "  /* Text Colors */\n";
        $css .= "  --chatbot-text-primary: #111827; /* Main text */\n";
        $css .= "  --chatbot-text-secondary: #4b5563; /* Secondary text */\n";
        $css .= "  --chatbot-text-tertiary: #6b7280; /* Muted text */\n";
        $css .= "  --chatbot-text-inverse: #ffffff; /* Text on dark backgrounds */\n\n";
        
        // Background colors  
        $css .= "  /* Background Colors */\n";
        $css .= "  --chatbot-bg-primary: #ffffff; /* Main background */\n";
        $css .= "  --chatbot-bg-secondary: #f9fafb; /* Secondary background */\n";
        $css .= "  --chatbot-bg-tertiary: #f3f4f6; /* Tertiary background */\n\n";
        
        // Message specific colors
        $css .= "  /* Message Bubbles */\n";
        $css .= "  --chatbot-message-user-bg: var(--chatbot-primary-500); /* Your messages */\n";
        $css .= "  --chatbot-message-user-text: var(--chatbot-text-inverse);\n";
        $css .= "  --chatbot-message-bot-bg: #f3f4f6; /* Bot messages */\n";
        $css .= "  --chatbot-message-bot-text: var(--chatbot-text-primary);\n\n";
        
        // Button colors
        $css .= "  /* Buttons */\n";
        $css .= "  --chatbot-button-primary-bg: var(--chatbot-primary-500);\n";
        $css .= "  --chatbot-button-primary-text: var(--chatbot-text-inverse);\n";
        $css .= "  --chatbot-button-primary-hover: var(--chatbot-primary-600);\n\n";
        
        // Border colors
        $css .= "  /* Borders */\n";
        $css .= "  --chatbot-border-primary: #e5e7eb;\n";
        $css .= "  --chatbot-border-secondary: #d1d5db;\n";
        
        $css .= "}\n\n";
        
        $css .= "/* 💡 Quick Customization Examples */\n";
        $css .= "/*\n";
        $css .= "  Red Theme:\n";
        $css .= "  --chatbot-primary-500: #ef4444;\n";
        $css .= "  --chatbot-primary-600: #dc2626;\n\n";
        $css .= "  Green Theme:\n";
        $css .= "  --chatbot-primary-500: #22c55e;\n";
        $css .= "  --chatbot-primary-600: #16a34a;\n\n";
        $css .= "  Purple Theme:\n";
        $css .= "  --chatbot-primary-500: #8b5cf6;\n";
        $css .= "  --chatbot-primary-600: #7c3aed;\n";
        $css .= "*/\n";
        
        return $css;
    }
    
    // =================================================================
    // STATIC METHODS
    // =================================================================
    
    public static function createDefaultForTenant(Tenant $tenant): self
    {
        return self::create([
            'tenant_id' => $tenant->id,
            'widget_name' => "Assistente {$tenant->name}",
            'welcome_message' => "Ciao! Sono l'assistente virtuale di {$tenant->name}. Come posso aiutarti oggi?",
            'theme' => 'default',
            'position' => 'bottom-right',
            'auto_open' => false,
            'enabled' => true
        ]);
    }
}