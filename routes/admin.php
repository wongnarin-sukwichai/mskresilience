<?php

use App\Http\Controllers\Admin\AboutController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\CalcTraceController;
use App\Http\Controllers\Admin\CommunityManagementController;
use App\Http\Controllers\Admin\CommunityMapController;
use App\Http\Controllers\Admin\DataImportController;
use App\Http\Controllers\Admin\DataRecordController;
use App\Http\Controllers\Admin\ExecutiveController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\FundingReportController;
use App\Http\Controllers\Admin\ImpactRecoveryController;
use App\Http\Controllers\Admin\ImportApprovalController;
use App\Http\Controllers\Admin\ImportBatchManagementController;
use App\Http\Controllers\Admin\ImportHistoryController;
use App\Http\Controllers\Admin\LoginLogController;
use App\Http\Controllers\Admin\Mcr2030KpiController;
use App\Http\Controllers\Admin\RiskFactorsController;
use App\Http\Controllers\Admin\RiskOverviewController;
use App\Http\Controllers\Admin\ScoringCriteriaController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Http\Controllers\Admin\TorMatrixController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\YearComparisonController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // The 6 display pages (reset to empty placeholders 2026-10-29, rebuilt one at a time per the client's 5-page
    // display spec + MCR2030 & KPI). Open to every role. Access is split by module instead: จัดการข้อมูล is
    // admin+staff, ตั้งค่าระบบ is admin. 'dashboard' stays the route name and URL for เกี่ยวกับระบบ because the
    // post-login and guest redirects target route('dashboard').
    Route::middleware('role:admin,staff,member')->group(function () {
        Route::get('dashboard', [AboutController::class, 'index'])->name('dashboard');
        // สรุปสำหรับผู้บริหาร (Mayor View, TOR 4.1.1(3)); generating the AI summary is admin-only (below).
        Route::get('executive', [ExecutiveController::class, 'index'])->name('executive.index');
        // ส่งออก Excel (TOR 4.4-4) of the main tables; the same data and filters as their pages.
        Route::get('export/risk', [ExportController::class, 'risk'])->name('export.risk');
        Route::get('export/events', [ExportController::class, 'events'])->name('export.events');
        Route::get('export/kpi', [ExportController::class, 'kpi'])->name('export.kpi');
        Route::get('export/flags', [ExportController::class, 'flags'])->name('export.flags');
        // รายงานหลักฐานประกอบการเสนอของบประมาณรายชุมชน (TOR 4.1.1(5)-3), a print-ready page.
        Route::get('reports/funding/{code}', [FundingReportController::class, 'show'])->name('reports.funding');
        Route::get('risk-overview', [RiskOverviewController::class, 'index'])->name('risk-overview.index');
        Route::get('community-map', [CommunityMapController::class, 'index'])->name('community-map.index');
        Route::get('risk-factors', [RiskFactorsController::class, 'index'])->name('risk-factors.index');
        Route::get('impact-recovery', [ImpactRecoveryController::class, 'index'])->name('impact-recovery.index');
        Route::get('mcr2030-kpi', [Mcr2030KpiController::class, 'index'])->name('mcr2030-kpi.index');
        // เปรียบเทียบรายปี: two fiscal years side by side, with the data year and criteria set behind each number.
        Route::get('year-comparison', [YearComparisonController::class, 'index'])->name('year-comparison.index');
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

        // แก้ไขข้อมูล (TOR 4.1.1(6)-2): add / edit / delete one row in the web, per กลุ่มงาน the user may import.
        Route::get('data-records', [DataRecordController::class, 'index'])->name('data-records.index');
        Route::post('data-records/{group}', [DataRecordController::class, 'store'])->name('data-records.store');
        Route::put('data-records/{group}/{record}', [DataRecordController::class, 'update'])->name('data-records.update');
        Route::delete('data-records/{group}/{record}', [DataRecordController::class, 'destroy'])->name('data-records.destroy');
    });

    // อนุมัติข้อมูล (TOR 4.1.1(6)-1): admin approves or rejects what staff sent while approval mode is on.
    Route::middleware('role:admin')->group(function () {
        Route::get('import-approvals', [ImportApprovalController::class, 'index'])->name('import-approvals.index');
        Route::post('executive/ai-summary', [ExecutiveController::class, 'generate'])->name('executive.ai-summary');
        Route::post('import-approvals/{batch}/approve', [ImportApprovalController::class, 'approve'])->name('import-approvals.approve');
        Route::post('import-approvals/{batch}/reject', [ImportApprovalController::class, 'reject'])->name('import-approvals.reject');
    });

    // ตั้งค่าระบบ module. Managing accounts/roles, the community registry, and scoring-criteria weights are
    // admin-only. An accidental weight edit would silently reprice every historical HEVC score (same reasoning
    // as account/role management).
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['create', 'edit', 'show']);

        Route::resource('communities', CommunityManagementController::class)->except(['create', 'edit', 'show']);

        // "ดูวิธีคิด": one community's real engine trace as JSON, for the dialog on pages 3/4 (admin only, owner
        // 2026-11-10). It replaced the temporary /calc-preview mock menu.
        Route::get('calc-trace/{code}', [CalcTraceController::class, 'show'])->name('calc-trace.show');

        // จัดการประวัตินำเข้า: every import, searchable. Cancelling posts to import-history.cancel.
        Route::get('import-batches', [ImportBatchManagementController::class, 'index'])->name('import-batches.index');
        Route::post('import-batches/delete-files', [ImportBatchManagementController::class, 'deleteFiles'])->name('import-batches.delete-files');

        Route::resource('scoring-criteria', ScoringCriteriaController::class)
            ->except(['create', 'edit', 'show'])
            ->parameters(['scoring-criteria' => 'set']);
        Route::post('scoring-criteria/{set}/lock', [ScoringCriteriaController::class, 'lock'])->name('scoring-criteria.lock');
        Route::post('scoring-criteria/{set}/unlock', [ScoringCriteriaController::class, 'unlock'])->name('scoring-criteria.unlock');

        // ตั้งค่าทั่วไป: system switches (TOR reference badges, the home-page mock-data notice, …), App\Support\Settings.
        Route::get('system-settings', [SystemSettingsController::class, 'index'])->name('system-settings.index');
        Route::put('system-settings', [SystemSettingsController::class, 'update'])->name('system-settings.update');

        // บันทึกการแก้ไข (TOR 4.1.1(2)-4, คุณลักษณะ 2.1-6) and ประวัติการเข้าใช้งาน (คุณลักษณะ 3-4, 11.4).
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('login-logs', [LoginLogController::class, 'index'])->name('login-logs.index');

        // สำรองข้อมูล (TOR 4.4-5, คุณลักษณะ 2.1-5): run a backup now, see the log, download a copy.
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');

        // ตารางเทียบ TOR: every clause → where it is in the system → status (App\Support\TorMatrix), printable.
        Route::get('tor-matrix', [TorMatrixController::class, 'index'])->name('tor-matrix.index');
    });
});
