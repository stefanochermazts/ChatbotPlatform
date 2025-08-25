<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class WidgetThemeController extends Controller
{
    /**
     * Restituisce la configurazione tema/layout del widget per un tenant.
     * Endpoint pubblico read-only: non include dati sensibili (API key, domini).
     */
    public function publicTheme(Tenant $tenant): JsonResponse
    {
        $config = $tenant->widgetConfig ?? null;
        if (!$config) {
            // Crea default on-the-fly se manca
            $config = \App\Models\WidgetConfig::createDefaultForTenant($tenant);
        }

        $theme = $config->theme_config;

        // Hardening: elimina campi non necessari/sensibili in theme_config
        unset($theme['customCSS']);
        unset($theme['advanced']);

        // Includi solo layout/widget e button, colori/brand/typography
        return response()->json($theme);
    }
}
