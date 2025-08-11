<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ScraperConfigController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\ChatCompletionsController;
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
    });
});


