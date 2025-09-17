<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WidgetConfig;
use Illuminate\Http\Request;

class WidgetPreviewController extends Controller
{
    /**
     * Preview widget with current configuration
     * 🚀 PUBLIC ROUTE - No authentication required
     */
    public function preview(Request $request, Tenant $tenant)
    {
        // Verify tenant exists and is active
        if (!$tenant) {
            abort(404, 'Tenant non trovato');
        }

        $config = $tenant->widgetConfig ?? WidgetConfig::createDefaultForTenant($tenant);
        
        // Apply temporary configuration for preview if provided
        if ($request->has('preview_config')) {
            try {
                $previewConfig = $request->input('preview_config');
                
                // Ensure preview_config is an array (could be JSON string)
                if (is_string($previewConfig)) {
                    $previewConfig = json_decode($previewConfig, true);
                }
                
                if (is_array($previewConfig)) {
                    $config->fill($previewConfig);
                }
            } catch (\Exception $e) {
                // Ignore preview config errors, use default config
            }
        }
        
        // Get the API key for this tenant
        $apiKey = $tenant->getWidgetApiKey();
        
        return view('widget.preview', compact('tenant', 'config', 'apiKey'));
    }
}












