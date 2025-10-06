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
            
            // ✅ CRITICAL: Registra il gate di autorizzazione usando Horizon::auth()
            Horizon::auth(function ($request) {
                // Controlla se l'utente è autenticato
                if (!auth()->check()) {
                    return false;
                }
                
                $user = auth()->user();
                
                // Permetti accesso solo a email specifiche
                return in_array($user->email, [
                    'admin@chatbot.local',
                    'admin@maia.chat',
                    'stefano@crowdm.com'
                ]);
            });
        }
    }
}

