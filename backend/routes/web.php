<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentAdminController;
use App\Http\Controllers\Admin\RagTestController;
use App\Http\Controllers\Admin\ScraperAdminController;
use App\Http\Controllers\Admin\TenantAdminController;
use App\Http\Controllers\Admin\TenantFormController;
use App\Http\Controllers\Admin\FormSubmissionController;
use App\Http\Controllers\Admin\KnowledgeBaseAdminController;
use App\Http\Controllers\Admin\WidgetConfigController;
use App\Http\Controllers\Admin\WidgetAnalyticsController;
use App\Http\Controllers\WidgetPreviewController;
use App\Http\Middleware\EnsureAdminToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 🚀 Public widget preview route (NO authentication required)
Route::get('/widget/preview/{tenant}', [App\Http\Controllers\WidgetPreviewController::class, 'preview'])->name('widget.preview');

// Rotte admin specifiche altrove nel file già mappate su DocumentAdminController

// Admin auth
Route::get('/admin/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

Route::middleware([EnsureAdminToken::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Tenants
    Route::get('/tenants', [TenantAdminController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantAdminController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantAdminController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}/edit', [TenantAdminController::class, 'edit'])->name('tenants.edit');
    Route::put('/tenants/{tenant}', [TenantAdminController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [TenantAdminController::class, 'destroy'])->name('tenants.destroy');
    // KB CRUD minimal
    Route::post('/tenants/{tenant}/kb', [KnowledgeBaseAdminController::class, 'store'])->name('tenants.kb.store');
    Route::put('/tenants/{tenant}/kb/{knowledgeBase}', [KnowledgeBaseAdminController::class, 'update'])->name('tenants.kb.update');
    Route::delete('/tenants/{tenant}/kb/{knowledgeBase}', [KnowledgeBaseAdminController::class, 'destroy'])->name('tenants.kb.destroy');
    // Bulk associazione documenti→KB
    Route::post('/tenants/{tenant}/documents/bulk-assign-kb', [TenantAdminController::class, 'bulkAssignKb'])->name('tenants.documents.bulk-assign-kb');
    // API Keys management
    Route::post('/tenants/{tenant}/api-keys', [TenantAdminController::class, 'createApiKey'])->name('tenants.api-keys.create');
    Route::delete('/tenants/{tenant}/api-keys/{keyId}', [TenantAdminController::class, 'revokeApiKey'])->name('tenants.api-keys.revoke');

    // Tenant Forms
    Route::resource('forms', TenantFormController::class);
    Route::post('/forms/{form}/toggle-active', [TenantFormController::class, 'toggleActive'])->name('forms.toggle-active');
    Route::get('/forms/{form}/preview', [TenantFormController::class, 'preview'])->name('forms.preview');
    Route::post('/forms/{form}/test-submit', [TenantFormController::class, 'testSubmit'])->name('forms.test-submit');

    // Form Submissions Management
    Route::prefix('forms')->name('forms.')->group(function () {
        Route::get('/submissions', [FormSubmissionController::class, 'index'])->name('submissions.index');
        Route::get('/submissions/{submission}', [FormSubmissionController::class, 'show'])->name('submissions.show');
        Route::get('/submissions/{submission}/respond', [FormSubmissionController::class, 'respond'])->name('submissions.respond');
        Route::post('/submissions/{submission}/response', [FormSubmissionController::class, 'sendResponse'])->name('submissions.send-response');
        Route::patch('/submissions/{submission}/status', [FormSubmissionController::class, 'updateStatus'])->name('submissions.update-status');
        Route::delete('/submissions/{submission}', [FormSubmissionController::class, 'destroy'])->name('submissions.destroy');
        Route::get('/submissions/export/csv', [FormSubmissionController::class, 'export'])->name('submissions.export');
        Route::get('/submissions/stats/ajax', [FormSubmissionController::class, 'stats'])->name('submissions.stats');
    });

    // Documents
    Route::get('/tenants/{tenant}/documents', [DocumentAdminController::class, 'index'])
        ->name('documents.index')
        ->whereNumber('tenant');
    Route::post('/tenants/{tenant}/documents/upload', [DocumentAdminController::class, 'upload'])
        ->name('documents.upload')
        ->whereNumber('tenant');
    // Specifica PRIMA delle rotte con {document} per evitare match errati
    Route::delete('/tenants/{tenant}/documents/by-kb', [DocumentAdminController::class, 'destroyByKb'])
        ->name('documents.destroyByKb')
        ->whereNumber('tenant');
    Route::post('/tenants/{tenant}/documents/{document}/retry', [DocumentAdminController::class, 'retry'])
        ->name('documents.retry')
        ->whereNumber('tenant')
        ->whereNumber('document');
    Route::delete('/tenants/{tenant}/documents/{document}', [DocumentAdminController::class, 'destroy'])
        ->name('documents.destroy')
        ->whereNumber('tenant')
        ->whereNumber('document');
    Route::delete('/tenants/{tenant}/documents', [DocumentAdminController::class, 'destroyAll'])
        ->name('documents.destroyAll')
        ->whereNumber('tenant');

    // Scraper config
    Route::get('/tenants/{tenant}/scraper', [ScraperAdminController::class, 'edit'])->name('scraper.edit');
    Route::post('/tenants/{tenant}/scraper', [ScraperAdminController::class, 'update'])->name('scraper.update');
    Route::delete('/tenants/{tenant}/scraper/{scraperConfig}', [ScraperAdminController::class, 'destroy'])->name('scraper.destroy');
    Route::post('/tenants/{tenant}/scraper/run', [ScraperAdminController::class, 'run'])->name('scraper.run');
    Route::post('/tenants/{tenant}/scraper/run-sync', [ScraperAdminController::class, 'runSync'])->name('scraper.run-sync');

    // RAG tester
    Route::get('/rag', [RagTestController::class, 'index'])->name('rag.index');
    Route::post('/rag/run', [RagTestController::class, 'run'])->name('rag.run');

    // Widget Configuration
    Route::get('/widget-config', [WidgetConfigController::class, 'index'])->name('widget-config.index');
    Route::get('/tenants/{tenant}/widget-config', [WidgetConfigController::class, 'show'])->name('widget-config.show');
    Route::get('/tenants/{tenant}/widget-config/edit', [WidgetConfigController::class, 'edit'])->name('widget-config.edit');
    Route::put('/tenants/{tenant}/widget-config', [WidgetConfigController::class, 'update'])->name('widget-config.update');
    Route::get('/tenants/{tenant}/widget-config/generate-embed', [WidgetConfigController::class, 'generateEmbed'])->name('widget-config.generate-embed');
    Route::get('/tenants/{tenant}/widget-config/generate-css', [WidgetConfigController::class, 'generateCSS'])->name('widget-config.generate-css');
    Route::get('/tenants/{tenant}/widget-config/current-colors', [WidgetConfigController::class, 'getCurrentColors'])->name('widget-config.current-colors');
    Route::get('/tenants/{tenant}/widget-config/test-api', [WidgetConfigController::class, 'testApi'])->name('widget-config.test-api');
    // Route::get('/tenants/{tenant}/widget-config/preview', [WidgetConfigController::class, 'preview'])->name('widget-config.preview'); // ⚠️ MOVED TO PUBLIC ROUTE

    // RAG Configuration
    Route::get('/tenants/{tenant}/rag-config', [App\Http\Controllers\Admin\TenantRagConfigController::class, 'show'])->name('rag-config.show');
Route::post('/tenants/{tenant}/rag-config', [App\Http\Controllers\Admin\TenantRagConfigController::class, 'update'])->name('rag-config.update');
Route::delete('/tenants/{tenant}/rag-config', [App\Http\Controllers\Admin\TenantRagConfigController::class, 'reset'])->name('rag-config.reset');
Route::get('/rag-config/profile-template', [App\Http\Controllers\Admin\TenantRagConfigController::class, 'getProfileTemplate'])->name('rag-config.profile-template');
Route::post('/tenants/{tenant}/rag-config/test', [App\Http\Controllers\Admin\TenantRagConfigController::class, 'testConfig'])->name('rag-config.test');

// 🎯 NUOVE ROUTE: Scraping singolo URL e re-scraping documenti
Route::post('/scraper/single-url', [App\Http\Controllers\Admin\ScraperAdminController::class, 'scrapeSingleUrl'])->name('scraper.single-url');
Route::post('/documents/{document}/rescrape', [DocumentAdminController::class, 'rescrape'])->name('documents.rescrape');
Route::post('/tenants/{tenant}/documents/rescrape-all', [DocumentAdminController::class, 'rescrapeAll'])->name('documents.rescrape-all');

    // Widget Analytics
    Route::get('/widget-analytics', [WidgetAnalyticsController::class, 'index'])->name('widget-analytics.index');
    Route::get('/tenants/{tenant}/widget-analytics', [WidgetAnalyticsController::class, 'show'])->name('widget-analytics.show');
    Route::get('/tenants/{tenant}/widget-analytics/export', [WidgetAnalyticsController::class, 'export'])->name('widget-analytics.export');
});

// Public routes - accessible without authentication
// Widget preview è ora disponibile solo tramite admin authentication
