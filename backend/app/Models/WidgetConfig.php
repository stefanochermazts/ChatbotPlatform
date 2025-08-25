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
        return [
            'name' => $this->widget_name,
            'theme' => $this->theme,
            'colors' => $this->custom_colors ?? [],
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
            'apiKey' => $this->tenant->getWidgetApiKey(),
            'tenantId' => $this->tenant->id, // Use numeric ID instead of slug
            'theme' => $this->theme,
            'position' => $this->position,
            'autoOpen' => $this->auto_open,
            'enableConversationContext' => $this->enable_conversation_context,
            'enableAnalytics' => $this->enable_analytics,
            'model' => $this->api_model,
            'temperature' => $this->temperature,
            'maxTokens' => $this->max_tokens
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