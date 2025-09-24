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
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
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

// 🎯 Agent Console - Conversation Management
Route::prefix('v1/conversations')->group(function () {
    // 🚀 Session Management
    Route::post('/start', [\App\Http\Controllers\Api\ConversationController::class, 'start']);
    Route::get('/{sessionId}', [\App\Http\Controllers\Api\ConversationController::class, 'show']);
    Route::post('/{sessionId}/end', [\App\Http\Controllers\Api\ConversationController::class, 'end']);
    Route::get('/{sessionId}/status', [\App\Http\Controllers\Api\ConversationController::class, 'status']);
    
    // 💬 Message Management  
    Route::get('/{sessionId}/messages', [\App\Http\Controllers\Api\MessageController::class, 'index']);
    Route::post('/messages/send', [\App\Http\Controllers\Api\MessageController::class, 'send']);
    Route::post('/messages/{messageId}/feedback', [\App\Http\Controllers\Api\MessageController::class, 'feedback']);
    Route::put('/messages/{messageId}/edit', [\App\Http\Controllers\Api\MessageController::class, 'edit']);
    
    // 🤝 Handoff request (public - called by widget)
    Route::post('/handoff/request', [\App\Http\Controllers\Api\HandoffController::class, 'request']);
});

// 🤝 Agent Console - Handoff Management (require auth)
Route::prefix('v1/handoffs')->middleware('auth.apikey')->group(function () {
    Route::post('/{handoffId}/assign', [\App\Http\Controllers\Api\HandoffController::class, 'assign']);
    Route::post('/{handoffId}/resolve', [\App\Http\Controllers\Api\HandoffController::class, 'resolve']);
    Route::post('/{handoffId}/escalate', [\App\Http\Controllers\Api\HandoffController::class, 'escalate']);
    Route::get('/pending', [\App\Http\Controllers\Api\HandoffController::class, 'pending']);
    Route::get('/metrics', [\App\Http\Controllers\Api\HandoffController::class, 'metrics']);
});

// 👨‍💼 Agent Console - Operator Management (require auth)
Route::prefix('v1/operators')->middleware('auth.apikey')->group(function () {
    Route::get('/available', [\App\Http\Controllers\Api\OperatorController::class, 'available']);
    Route::post('/status', [\App\Http\Controllers\Api\OperatorController::class, 'updateStatus']);
    Route::get('/{operatorId}/conversations', [\App\Http\Controllers\Api\OperatorController::class, 'conversations']);
    Route::get('/{operatorId}/metrics', [\App\Http\Controllers\Api\OperatorController::class, 'metrics']);
    Route::post('/heartbeat', [\App\Http\Controllers\Api\OperatorController::class, 'heartbeat']);
});

// Health check endpoint
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});


