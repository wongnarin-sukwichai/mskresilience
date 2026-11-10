<?php

use App\Http\Controllers\Admin\CommunityManagementController;
use App\Http\Controllers\Admin\DataImportController;
use App\Http\Controllers\Admin\ImportBatchManagementController;
use App\Http\Controllers\Admin\ImportHistoryController;
use App\Http\Controllers\Admin\ScoringCriteriaController;
use App\Http\Controllers\Admin\UserManagementController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    // The 6 display pages (reset to empty placeholders 2026-10-29, rebuilt one at a time per the client's 5-page
    // display spec + MCR2030 & KPI). Open to every role. Access is split by module instead: จัดการข้อมูล is
    // admin+staff, ตั้งค่าระบบ is admin. 'dashboard' stays the route name and URL for เกี่ยวกับระบบ because the
    // post-login and guest redirects target route('dashboard').
    Route::middleware('role:admin,staff,member')->group(function () {
        Route::get('dashboard', fn () => Inertia::render('admin/About'))->name('dashboard');
        Route::get('risk-overview', fn () => Inertia::render('admin/RiskOverview'))->name('risk-overview.index');
        Route::get('community-map', fn () => Inertia::render('admin/CommunityMap'))->name('community-map.index');
        Route::get('risk-factors', fn () => Inertia::render('admin/RiskFactors'))->name('risk-factors.index');
        Route::get('impact-recovery', fn () => Inertia::render('admin/ImpactRecovery'))->name('impact-recovery.index');
        Route::get('mcr2030-kpi', fn () => Inertia::render('admin/Mcr2030Kpi'))->name('mcr2030-kpi.index');
    });

    // จัดการข้อมูล module — admin and staff. Per-sheet (กลุ่มงาน) import permission comes from
    // User::canImport(), checked in DataImportController; {group} is an App\Enums\WorkGroup value (404 if not).
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('data-import', [DataImportController::class, 'index'])->name('data-import.index');
        Route::get('data-import/{group}', [DataImportController::class, 'show'])->name('data-import.show');
        Route::get('data-import/{group}/template', [DataImportController::class, 'template'])->name('data-import.template');
        Route::post('data-import/{group}/validate', [DataImportController::class, 'validateFile'])->name('data-import.validate');
        Route::post('data-import/{group}/confirm', [DataImportController::class, 'confirm'])->name('data-import.confirm');
        Route::delete('data-import/{group}/pending', [DataImportController::class, 'cancel'])->name('data-import.cancel');

        // ประวัตินำเข้า: the imports of the groups this user may import. Cancel rules are in App\Import\ImportHistory.
        Route::get('import-history', [ImportHistoryController::class, 'index'])->name('import-history.index');
        Route::get('import-history/{batch}/data', [ImportHistoryController::class, 'data'])->name('import-history.data');
        Route::delete('import-history/{batch}', [ImportHistoryController::class, 'cancel'])->name('import-history.cancel');
    });

    // ตั้งค่าระบบ module. Managing accounts/roles, the community registry, and scoring-criteria weights are
    // admin-only. An accidental weight edit would silently reprice every historical HEVC score (same reasoning
    // as account/role management).
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['create', 'edit', 'show']);

        Route::resource('communities', CommunityManagementController::class)->except(['create', 'edit', 'show']);

        // ⚠️ TEMPORARY mock page (2026-11-03) so the owner can picture a "ตรวจสอบการคำนวณ" menu. Delete together with
        // pages/admin/CalcPreview.vue and its AdminLayout nav entry.
        Route::get('calc-preview', fn () => Inertia::render('admin/CalcPreview'))->name('calc-preview');

        // จัดการประวัตินำเข้า: every import, searchable. Cancelling posts to import-history.cancel.
        Route::get('import-batches', [ImportBatchManagementController::class, 'index'])->name('import-batches.index');
        Route::post('import-batches/delete-files', [ImportBatchManagementController::class, 'deleteFiles'])->name('import-batches.delete-files');

        Route::resource('scoring-criteria', ScoringCriteriaController::class)
            ->except(['create', 'edit', 'show'])
            ->parameters(['scoring-criteria' => 'set']);
        Route::post('scoring-criteria/{set}/lock', [ScoringCriteriaController::class, 'lock'])->name('scoring-criteria.lock');
        Route::post('scoring-criteria/{set}/unlock', [ScoringCriteriaController::class, 'unlock'])->name('scoring-criteria.unlock');
    });
});
