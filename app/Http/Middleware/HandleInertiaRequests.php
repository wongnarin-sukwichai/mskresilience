<?php

namespace App\Http\Middleware;

use App\Backup\BackupService;
use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ImportBatch;
use App\Support\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            // The admin header's community search (AdminLayout) — only for logged-in users; 32 rows of code + name.
            'communityIndex' => fn () => $request->user()
                ? Community::orderBy('code')->get(['code', 'name'])
                : [],
            // Admin sidebar badge on อนุมัติข้อมูล: imports/edits waiting for approval.
            'pendingApprovals' => fn () => $request->user()?->role === UserRole::Admin
                ? ImportBatch::where('status', 'pending')->whereNull('cancelled_at')->count()
                : 0,
            // Admin banner when the last database backup is too old (TOR 4.4-5): days since it, -1 = never, null = fine.
            'backupOverdue' => function () use ($request) {
                if ($request->user()?->role !== UserRole::Admin) {
                    return null;
                }
                $days = BackupService::daysSinceLastDatabaseBackup();

                return $days === null ? -1 : ($days >= config('backup.overdue_days') ? $days : null);
            },
            // TorRef.vue shows the "TOR …" badges only while this is on (ตั้งค่าทั่วไป), for the acceptance presentation.
            'showTorRefs' => fn () => $request->user() ? (bool) Settings::get('show_tor_refs') : false,
            // Controllers flash a Thai success message via back()->with('status', ...) or a refusal via
            // back()->with('error', ...); AdminLayout shows either as a toast (green / red).
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
