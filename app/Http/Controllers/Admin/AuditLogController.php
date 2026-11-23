<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * บันทึกการแก้ไข (ตั้งค่าระบบ module, admin): who changed which data, when, and the values before and after
 * (TOR 4.1.1(2)-4, 4.1.1(6), คุณลักษณะ 2.1-6). Filters are query-string, like จัดการประวัตินำเข้า.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user' => ['nullable', 'integer'],
            'log' => ['nullable', Rule::in(array_keys(AuditPresenter::LOGS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $activities = Activity::query()
            ->with('causer', 'subject')
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('causer_type', User::class)->where('causer_id', $id))
            ->when($filters['log'] ?? null, fn ($q, $log) => $q->where('log_name', $log))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w
                ->where('description', 'like', "%{$text}%")
                ->orWhere('properties', 'like', "%{$text}%")))
            ->latest('id')
            ->paginate(30)->withQueryString()
            ->through(fn (Activity $a) => AuditPresenter::row($a));

        return Inertia::render('admin/AuditLog', [
            'activities' => $activities,
            'filters' => [
                'user' => isset($filters['user']) ? (string) $filters['user'] : '',
                'log' => $filters['log'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'users' => User::orderBy('name')->get(['id', 'name']),
            'logs' => collect(AuditPresenter::LOGS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }
}
