<?php

require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Get tenant 8
$tenant = \App\Models\Tenant::with('widgetConfig')->find(8);

if (! $tenant) {
    echo "❌ Tenant 8 non trovato!\n";
    exit;
}

echo "🔍 DEBUG Widget Configuration for Tenant {$tenant->id}\n";
echo str_repeat('=', 60)."\n";

// Check if widgetConfig exists
if (! $tenant->widgetConfig) {
    echo "❌ widgetConfig non trovato! Creando default...\n";
    $config = \App\Models\WidgetConfig::createDefaultForTenant($tenant);
} else {
    echo "✅ widgetConfig trovato!\n";
    $config = $tenant->widgetConfig;
}

echo "\n📋 Widget Config Database Fields:\n";
echo str_repeat('-', 40)."\n";
echo "ID: {$config->id}\n";
echo "Tenant ID: {$config->tenant_id}\n";
echo 'Enabled: '.($config->enabled ? 'true' : 'false')."\n";
echo "Widget Name: {$config->widget_name}\n";
echo "Welcome Message: {$config->welcome_message}\n";
echo "Theme: {$config->theme}\n";
echo "Position: {$config->position}\n";
echo 'Auto Open: '.($config->auto_open ? 'true' : 'false')."\n";
echo 'Enable Conversation Context: '.($config->enable_conversation_context ? 'true' : 'false')."\n";
echo 'Enable Analytics: '.($config->enable_analytics ? 'true' : 'false')."\n";
echo "API Model: {$config->api_model}\n";
echo "Temperature: {$config->temperature}\n";
echo "Max Tokens: {$config->max_tokens}\n";

echo "\n🔧 Advanced Config:\n";
if ($config->advanced_config) {
    echo json_encode($config->advanced_config, JSON_PRETTY_PRINT)."\n";
} else {
    echo "null\n";
}

echo "\n🚀 Generated embed_config:\n";
echo str_repeat('-', 40)."\n";
try {
    $embedConfig = $config->embed_config;
    echo json_encode($embedConfig, JSON_PRETTY_PRINT)."\n";
} catch (\Exception $e) {
    echo '❌ Error generating embed_config: '.$e->getMessage()."\n";
}

echo "\n🔑 Tenant API Key:\n";
echo str_repeat('-', 40)."\n";
$apiKey = $tenant->getWidgetApiKey();
if ($apiKey) {
    echo '✅ API Key found: '.substr($apiKey, 0, 8)."...\n";
} else {
    echo "❌ No API Key found!\n";
}

echo "\n🎨 Theme Config:\n";
echo str_repeat('-', 40)."\n";
try {
    $themeConfig = $config->theme_config;
    echo json_encode($themeConfig, JSON_PRETTY_PRINT)."\n";
} catch (\Exception $e) {
    echo '❌ Error generating theme_config: '.$e->getMessage()."\n";
}

echo "\n📄 Preview URL:\n";
echo str_repeat('-', 40)."\n";
echo "http://chatbotplatform.test:8443/admin/tenants/{$tenant->id}/widget-config/preview\n";

echo "\n".str_repeat('=', 60)."\n";
echo "✅ Debug completed!\n";
