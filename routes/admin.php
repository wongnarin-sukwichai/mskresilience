<?php

use App\Http\Controllers\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->middleware('role:admin,staff,member')->name('dashboard');

    // Data import, community data, hazard analysis, and the L&D register are staff-level duties — open to
    // admin and staff, not member.
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('data-import', function () {
            return Inertia::render('admin/DataImport');
        })->name('data-import.index');

        // ข้อมูลชุมชน — replaces the old admin-only "จัดการชุมชน" CRUD page (removed). Sits right after
        // นำเข้าข้อมูล in both routing and the sidebar since it's the "view what's been imported/set up per
        // community" counterpart to that import flow, and per product decision is no longer admin-only.
        Route::get('community-data', function () {
            return Inertia::render('admin/CommunityData');
        })->name('community-data.index');

        Route::get('disaster-overview', function () {
            return Inertia::render('admin/DisasterOverview');
        })->name('disaster-overview.index');

        Route::get('hazard-analysis', function () {
            return Inertia::render('admin/HazardAnalysis');
        })->name('hazard-analysis.index');

        Route::get('events-ld', function () {
            return Inertia::render('admin/EventsLD');
        })->name('events-ld.index');
    });

    // Managing accounts/roles and scoring-criteria weights live under the "ตั้งค่า" (settings) submenu —
    // admin-only. An accidental weight edit would silently reprice every historical HEVC score (same
    // reasoning as account/role management). Community management (จัดการชุมชน) used to live here too but
    // was removed in favor of ข้อมูลชุมชน above (admin+staff, flat nav) per product decision.
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['create', 'edit', 'show']);

        Route::get('scoring-criteria', function () {
            return Inertia::render('admin/ScoringCriteria');
        })->name('scoring-criteria.index');
    });
});
