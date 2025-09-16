<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ScraperConfigController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\ChatCompletionsController;
use App\Http\Controllers\Api\VonageWhatsAppController;
use App\Http\Controllers\Api\WidgetEventController;
use App\Http\Controllers\Api\DocumentViewController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\WidgetThemeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::middleware('admin.token')->group(function (): void {
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::post('/tenants/{tenant}/users', [TenantController::class, 'addUser']);
        Route::post('/tenants/{tenant}/api-keys', [ApiKeyController::class, 'issue']);
        Route::delete('/tenants/{tenant}/api-keys/{keyId}', [ApiKeyController::class, 'revoke']);
    });

    Route::middleware('auth.apikey')->group(function (): void {
        Route::post('/chat/completions', [ChatCompletionsController::class, 'create']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/documents/batch', [DocumentController::class, 'storeBatch']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
        Route::post('/scraper/config', [ScraperConfigController::class, 'storeOrUpdate']);
        Route::get('/scraper/config', [ScraperConfigController::class, 'show']);
        
        // Widget Event Tracking
        Route::post('/widget/events', [WidgetEventController::class, 'track']);
        Route::get('/widget/session-stats', [WidgetEventController::class, 'sessionStats']);
        Route::get('/widget/health', [WidgetEventController::class, 'health']);
        
        // Document Citations & Viewing
        Route::post('/documents/view-token', [DocumentViewController::class, 'generateViewToken']);
        Route::post('/documents/info', [DocumentViewController::class, 'getDocumentInfo']);
        
        // Quick Actions (protected by API key)
        Route::prefix('quick-actions')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\QuickActionController::class, 'index']);
            Route::post('/execute', [\App\Http\Controllers\Api\QuickActionController::class, 'execute']);
        });
        
        // Dynamic Forms (protected by API key)
        Route::prefix('forms')->group(function () {
            Route::post('/check-triggers', [FormController::class, 'checkTriggers']);
            Route::post('/submit', [FormController::class, 'submit']);
            Route::get('/submissions', [FormController::class, 'listSubmissions']);
        });
        
        // Feedback del Chatbot (protected by API key)
        Route::prefix('feedback')->group(function () {
            Route::post('/', [\App\Http\Controllers\Api\ChatbotFeedbackController::class, 'store']);
            Route::get('/stats', [\App\Http\Controllers\Api\ChatbotFeedbackController::class, 'stats']);
        });
    });
    
    // Public document viewing (no API key needed, uses secure token)
    Route::get('/documents/view/{token}', [DocumentViewController::class, 'viewDocument'])->name('api.document.view');

    // Public widget theme (read-only, safe values only)
    Route::get('/tenants/{tenant}/widget-theme', [WidgetThemeController::class, 'publicTheme']);
});

// Public widget events (for embed tracking without API key)
Route::post('/v1/widget/events/public', [WidgetEventController::class, 'trackPublic']);

// Vonage Webhooks (public - no auth needed)
Route::prefix('v1/vonage/whatsapp')->group(function () {
    Route::post('/inbound', [VonageWhatsAppController::class, 'inbound']);
    Route::post('/status', [VonageWhatsAppController::class, 'status']);
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});


