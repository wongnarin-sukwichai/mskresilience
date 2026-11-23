<?php

namespace App\Providers;

use App\Models\Community;
use App\Models\DisasterEvent;
use App\Models\DisasterRecord;
use App\Models\EducationRecord;
use App\Models\EngineeringRecord;
use App\Models\HealthRecord;
use App\Models\ImportBatch;
use App\Models\KpiRecord;
use App\Models\LoginLog;
use App\Models\PublicRelationsRecord;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Models\WelfareRecord;
use App\Scoring\EngineCache;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Support\Facades\Event;
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
        // user straight to route('dashboard') if it exists — with no regard for role. Keep this in sync with
        // User::canAccessAdminPanel() and AuthenticatedSessionController::store().
        RedirectIfAuthenticated::redirectUsing(
            fn ($request) => $request->user()?->canAccessAdminPanel() ? route('dashboard') : route('home'),
        );

        // Cached engine results (EngineCache) are dropped whenever anything they're built from changes: an import,
        // a cancel, a web edit, a criteria set, a community. Every write path uses Eloquent models, so model events
        // catch them all. A new data table must be added here.
        foreach ([
            DisasterRecord::class, WelfareRecord::class, HealthRecord::class, EducationRecord::class,
            EngineeringRecord::class, PublicRelationsRecord::class, DisasterEvent::class, KpiRecord::class,
            Community::class, ScoringCriteriaSet::class, ImportBatch::class,
        ] as $model) {
            $model::saved(fn () => EngineCache::flush());
            $model::deleted(fn () => EngineCache::flush());
        }

        // ประวัติการเข้าใช้งาน (TOR 4.1.1(6), คุณลักษณะ 3-4, 11.4). Logout only fires when the user clicks ออกจากระบบ;
        // a session that just expires has no event, so the log page also shows each account's last activity.
        Event::listen(Login::class, fn (Login $e) => $e->user instanceof User ? LoginLog::record('login', $e->user) : null);
        Event::listen(Logout::class, fn (Logout $e) => $e->user instanceof User ? LoginLog::record('logout', $e->user) : null);
        Event::listen(Failed::class, fn (Failed $e) => LoginLog::record(
            'failed',
            $e->user instanceof User ? $e->user : null,
            $e->credentials['email'] ?? null,
        ));
    }
}
