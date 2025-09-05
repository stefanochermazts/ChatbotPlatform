<?php

namespace App\Providers;

use App\Listeners\SendUserInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }

        // Registra event listeners
        Event::listen(
            Registered::class,
            SendUserInvitation::class,
        );

        // Registra policies
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
    }
}
