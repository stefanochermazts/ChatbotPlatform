<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            // ✅ PRODUZIONE: Permetti accesso solo agli admin
            // Modifica in base alla tua logica di autorizzazione
            
            // Opzione 1: Permetti solo email specifiche
            return in_array($user->email, [
                'admin@chatbot.local',
                'admin@maia.chat',
                // Aggiungi altre email admin
            ]);
            
            // Opzione 2: Controlla ruolo admin (se hai un campo role)
            // return $user->role === 'admin' || $user->is_admin === true;
            
            // Opzione 3: Controlla se è un super admin
            // return $user->hasRole('super-admin');
        });
    }
}

