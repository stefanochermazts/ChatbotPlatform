<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentAdminController;
use App\Http\Controllers\Admin\RagTestController;
use App\Http\Controllers\Admin\ScraperAdminController;
use App\Http\Controllers\Admin\TenantAdminController;
use App\Http\Controllers\Admin\KnowledgeBaseAdminController;
use App\Http\Middleware\EnsureAdminToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
});
