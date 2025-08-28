<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WidgetConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WidgetConfigController extends Controller
{
    /**
     * Display widget configurations list
     */
    public function index(Request $request)
    {
        // Determina i tenant da mostrare in base ai filtri
        $tenantQuery = Tenant::query()->orderBy('name');
        if ($request->filled('tenant_id')) {
            $tenantQuery->where('id', (int) $request->tenant_id);
        }
        $tenants = $tenantQuery->get();

        // Assicura che ogni tenant abbia una configurazione (crea default se mancante)
        foreach ($tenants as $t) {
            if (!$t->widgetConfig) {
                WidgetConfig::createDefaultForTenant($t);
            }
        }

        // Ora carica le configurazioni con i filtri richiesti
        $configsQuery = WidgetConfig::with(['tenant', 'updatedBy'])
            ->whereIn('tenant_id', $tenants->pluck('id')->all())
            ->orderBy('updated_at', 'desc');

        if ($request->filled('enabled')) {
            $configsQuery->where('enabled', $request->enabled === '1');
        }

        $configs = $configsQuery->paginate(20);

        // La tendina dei tenant deve mostrare tutti i tenant (non solo filtrati)
        $allTenants = Tenant::orderBy('name')->get();

        return view('admin.widget-config.index', [
            'configs' => $configs,
            'tenants' => $allTenants,
        ]);
    }
    
    /**
     * Show configuration for specific tenant
     */
    public function show(Tenant $tenant)
    {
        $config = $tenant->widgetConfig ?? WidgetConfig::createDefaultForTenant($tenant);
        
        // Get the API key for this tenant
        $apiKey = $tenant->getWidgetApiKey();
        
        return view('admin.widget-config.show', compact('tenant', 'config', 'apiKey'));
    }
    
    /**
     * Show form to edit widget configuration
     */
    public function edit(Tenant $tenant)
    {
        $config = $tenant->widgetConfig ?? WidgetConfig::createDefaultForTenant($tenant);
        
        // Get the API key for this tenant
        $apiKey = $tenant->getWidgetApiKey();
        
        $themes = [
            'default' => 'Default Blue',
            'corporate' => 'Corporate Gray',
            'friendly' => 'Friendly Green',
            'high-contrast' => 'High Contrast'
        ];
        
        $positions = [
            'bottom-right' => 'Bottom Right',
            'bottom-left' => 'Bottom Left',
            'top-right' => 'Top Right',
            'top-left' => 'Top Left'
        ];
        
        $models = [
            'gpt-4o-mini' => 'GPT-4o Mini (Consigliato)',
            'gpt-4o' => 'GPT-4o',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo'
        ];
        
        return view('admin.widget-config.edit', compact('tenant', 'config', 'apiKey', 'themes', 'positions', 'models'));
    }
    
    /**
     * Update widget configuration
     */
    public function update(Request $request, Tenant $tenant)
    {
        $config = $tenant->widgetConfig ?? new WidgetConfig(['tenant_id' => $tenant->id]);
        
        $validated = $request->validate([
            // Basic Configuration
            'enabled' => 'boolean',
            'widget_name' => 'required|string|max:255',
            'welcome_message' => 'nullable|string|max:1000',
            'position' => ['required', Rule::in(['bottom-right', 'bottom-left', 'top-right', 'top-left'])],
            'auto_open' => 'boolean',
            
            // Theme Configuration
            'theme' => ['required', Rule::in(['default', 'corporate', 'friendly', 'high-contrast', 'custom'])],
            'custom_colors' => 'nullable|array',
            'custom_colors.primary' => 'nullable|array',
            'custom_colors.primary.*' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo_url' => 'nullable|url|max:255',
            'favicon_url' => 'nullable|url|max:255',
            'font_family' => 'nullable|string|max:255',
            
            // Layout Configuration
            'widget_width' => 'nullable|string|max:20',
            'widget_height' => 'nullable|string|max:20',
            'border_radius' => 'nullable|string|max:20',
            'button_size' => 'nullable|string|max:20',
            
            // Behavior Configuration
            'show_header' => 'boolean',
            'show_avatar' => 'boolean',
            'show_close_button' => 'boolean',
            'enable_animations' => 'boolean',
            'enable_dark_mode' => 'boolean',
            
            // API Configuration
            'api_model' => ['required', Rule::in(['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'])],
            'temperature' => 'required|numeric|min:0|max:2',
            'max_tokens' => 'required|integer|min:1|max:4000',
            'enable_conversation_context' => 'boolean',
            
            // Security Configuration
            'allowed_domains' => 'nullable|array',
            'allowed_domains.*' => 'nullable|string|max:255',
            'enable_analytics' => 'boolean',
            'gdpr_compliant' => 'boolean',
            
            // Custom CSS/JS
            'custom_css' => 'nullable|string|max:50000',
            'custom_js' => 'nullable|string|max:50000',
        ]);
        
        // Set defaults for boolean fields
        $booleanFields = [
            'enabled', 'auto_open', 'show_header', 'show_avatar', 'show_close_button',
            'enable_animations', 'enable_dark_mode', 'enable_conversation_context',
            'enable_analytics', 'gdpr_compliant'
        ];
        
        foreach ($booleanFields as $field) {
            $validated[$field] = $request->boolean($field);
        }
        
        // Handle file uploads for logo and favicon
        if ($request->hasFile('logo_file')) {
            $logoPath = $this->handleFileUpload($request->file('logo_file'), 'logos', $tenant->slug);
            $validated['logo_url'] = Storage::url($logoPath);
        }
        
        if ($request->hasFile('favicon_file')) {
            $faviconPath = $this->handleFileUpload($request->file('favicon_file'), 'favicons', $tenant->slug);
            $validated['favicon_url'] = Storage::url($faviconPath);
        }
        
        // Clean allowed domains
        if (isset($validated['allowed_domains'])) {
            $validated['allowed_domains'] = array_filter(
                array_map('trim', $validated['allowed_domains']),
                fn($domain) => !empty($domain)
            );
        }
        
        $validated['updated_by'] = auth()->id();
        $validated['last_updated_at'] = now();
        
        $config->fill($validated);
        $config->save();
        
        return redirect()
            ->route('admin.widget-config.show', $tenant)
            ->with('success', 'Configurazione widget aggiornata con successo!');
    }
    
    /**
     * Generate and download embed code
     */
    public function generateEmbed(Tenant $tenant)
    {
        $config = $tenant->widgetConfig;
        
        if (!$config) {
            return redirect()->back()->with('error', 'Configurazione widget non trovata.');
        }
        
        $embedCode = $config->generateEmbedCode(config('app.url'));
        
        return response($embedCode)
            ->header('Content-Type', 'text/html')
            ->header('Content-Disposition', 'attachment; filename="chatbot-embed-' . $tenant->slug . '.html"');
    }
    
    /**
     * Generate and download theme CSS
     */
    public function generateCSS(Tenant $tenant)
    {
        $config = $tenant->widgetConfig;
        
        if (!$config) {
            return redirect()->back()->with('error', 'Configurazione widget non trovata.');
        }
        
        $css = $config->generateThemeCSS();
        
        return response($css)
            ->header('Content-Type', 'text/css')
            ->header('Content-Disposition', 'attachment; filename="chatbot-theme-' . $tenant->slug . '.css"');
    }
    
    /**
     * Get current design system colors as CSS for easy customization
     */
    public function getCurrentColors(Tenant $tenant)
    {
        $config = $tenant->widgetConfig ?? WidgetConfig::createDefaultForTenant($tenant);
        
        $css = $config->getCurrentColorsCSS();
        
        return response()->json([
            'success' => true,
            'css' => $css
        ]);
    }
    
    /**
     * Preview widget with current configuration
     */
    public function preview(Request $request, Tenant $tenant)
    {
        $config = $tenant->widgetConfig ?? WidgetConfig::createDefaultForTenant($tenant);
        
        // Apply temporary configuration for preview
        if ($request->has('preview_config')) {
            $previewConfig = $request->input('preview_config');
            $config->fill($previewConfig);
        }
        
        // Get the API key for this tenant
        $apiKey = $tenant->getWidgetApiKey();
        
        return view('admin.widget-config.preview', compact('tenant', 'config', 'apiKey'));
    }
    
    /**
     * Test widget API integration
     */
    public function testApi(Tenant $tenant)
    {
        $config = $tenant->widgetConfig;
        
        if (!$config) {
            return response()->json(['error' => 'Configurazione widget non trovata.'], 404);
        }
        
        try {
            return response()->json([
                'success' => true,
                'message' => 'API configurata correttamente',
                'config' => [
                    'model' => $config->api_model,
                    'temperature' => $config->temperature,
                    'max_tokens' => $config->max_tokens
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Handle file upload for logos and favicons
     */
    private function handleFileUpload($file, string $folder, string $tenantSlug): string
    {
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $extension = $file->getClientOriginalExtension();
        $fileName = $safeName.'-'.uniqid().'.'.$extension;
        return $file->storeAs('public/widget/'.$tenantSlug.'/'.$folder, $fileName);
    }
}