<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ✅ Solo in produzione: carica Horizon
        if ($this->app->environment('production')) {
            // Registra Horizon solo se disponibile
            if (class_exists(HorizonApplicationServiceProvider::class)) {
                $this->app->register(HorizonApplicationServiceProvider::class);
            }
            
            // 🚨 DEBUG: Disabilita temporaneamente auth per testare
            Horizon::auth(function ($request) {
                \Log::info('🔍 Horizon::auth() chiamato', [
                    'is_authenticated' => auth()->check(),
                    'user_email' => auth()->check() ? auth()->user()->email : 'N/A',
                    'request_path' => $request->path(),
                    'session_id' => session()->getId()
                ]);
                
                // TEMPORARY: Permetti accesso a tutti per debug
                return true;
                
                /* ORIGINAL CODE (da riabilitare dopo test):
                if (!auth()->check()) {
                    return false;
                }
                
                $user = auth()->user();
                
                return in_array($user->email, [
                    'admin@chatbot.local',
                    'admin@maia.chat',
                    'stefano@crowdm.com'
                ]);
                */
            });
        }
    }
}

