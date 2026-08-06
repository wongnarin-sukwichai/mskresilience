<?php

use App\Http\Controllers\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->middleware('role:admin,staff')->name('dashboard');

    // Managing accounts/roles is more sensitive than viewing the dashboard, so it's admin-only (not staff).
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['create', 'edit', 'show']);
    });
});
