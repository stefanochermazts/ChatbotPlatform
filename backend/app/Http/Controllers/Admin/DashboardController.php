<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;

class DashboardController extends Controller
{
    public function index()
    {
        $tenantCount = Tenant::count();
        
        // Carica configurazioni RAG per visualizzazione
        $rag = [
            'features' => config('rag.features', []),
            'hybrid' => config('rag.hybrid', []),
            'reranker' => config('rag.reranker', []),
            'multiquery' => config('rag.multiquery', []),
            'context' => config('rag.context', []),
            'cache' => config('rag.cache', []),
            'telemetry' => config('rag.telemetry', []),
        ];
        
        return view('admin.dashboard', compact('tenantCount', 'rag'));
    }
}

