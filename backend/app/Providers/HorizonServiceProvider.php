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
            
            // ✅ CRITICAL: Registra il gate di autorizzazione
            $this->gate();
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // ✅ Solo in produzione: definisci il gate
        if ($this->app->environment('production')) {
            Gate::define('viewHorizon', function ($user) {
                // ✅ PRODUZIONE: Permetti accesso solo agli admin
                // Modifica in base alla tua logica di autorizzazione
                
                // Opzione 1: Permetti solo email specifiche
                return in_array($user->email, [
                    'admin@chatbot.local',
                    'admin@maia.chat',
                    'stefano@crowdm.com'
                    // Aggiungi altre email admin
                ]);
                
                // Opzione 2: Controlla ruolo admin (se hai un campo role)
                // return $user->role === 'admin' || $user->is_admin === true;
                
                // Opzione 3: Controlla se è un super admin
                // return $user->hasRole('super-admin');
            });
        }
    }
}

