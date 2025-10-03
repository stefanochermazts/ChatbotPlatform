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

        // Aggiungi configurazione operatore
        $theme['operator'] = [
            'enabled' => $config->operator_enabled ?? false,
            'button_text' => $config->operator_button_text ?? 'Operatore',
            'button_icon' => $config->operator_button_icon ?? 'headphones',
            'availability' => $config->operator_availability ?? [],
            'unavailable_message' => $config->operator_unavailable_message ?? 'Operatore non disponibile in questo momento'
        ];

        // Includi solo layout/widget e button, colori/brand/typography
        return response()->json($theme);
    }
}
