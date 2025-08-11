<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentAdminController;
use App\Http\Controllers\Admin\RagTestController;
use App\Http\Controllers\Admin\ScraperAdminController;
use App\Http\Controllers\Admin\TenantAdminController;
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

    // Documents
    Route::get('/tenants/{tenant}/documents', [DocumentAdminController::class, 'index'])->name('documents.index');
    Route::post('/tenants/{tenant}/documents/upload', [DocumentAdminController::class, 'upload'])->name('documents.upload');
    Route::post('/tenants/{tenant}/documents/{document}/retry', [DocumentAdminController::class, 'retry'])->name('documents.retry');
    Route::delete('/tenants/{tenant}/documents/{document}', [DocumentAdminController::class, 'destroy'])->name('documents.destroy');

    // Scraper config
    Route::get('/tenants/{tenant}/scraper', [ScraperAdminController::class, 'edit'])->name('scraper.edit');
    Route::post('/tenants/{tenant}/scraper', [ScraperAdminController::class, 'update'])->name('scraper.update');

    // RAG tester
    Route::get('/rag', [RagTestController::class, 'index'])->name('rag.index');
    Route::post('/rag/run', [RagTestController::class, 'run'])->name('rag.run');
});
