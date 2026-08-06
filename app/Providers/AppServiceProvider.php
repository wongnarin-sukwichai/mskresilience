<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Support\ServiceProvider;

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
        // By default, the `guest` middleware (on /login, /register, etc.) sends an already-authenticated
        // user straight to route('dashboard') if it exists — with no regard for role. Since /dashboard is
        // gated to admin/staff, that default would bounce a logged-in member into a 403 instead of letting
        // them see (and use) the login form to switch accounts. Keep this in sync with
        // User::canAccessAdminPanel() and AuthenticatedSessionController::store().
        RedirectIfAuthenticated::redirectUsing(
            fn ($request) => $request->user()?->canAccessAdminPanel() ? route('dashboard') : route('home'),
        );
    }
}
