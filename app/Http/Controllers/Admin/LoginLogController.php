<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ประวัติการเข้าใช้งาน (ตั้งค่าระบบ module, admin; TOR 4.1.1(6), คุณลักษณะ 3-4, 11.4) plus "ผู้ใช้ที่ออนไลน์ขณะนี้",
 * the live count used in the 20-users-at-once acceptance test (คุณลักษณะ 2.1-3).
 *
 * Online = a logged-in session active within ONLINE_MINUTES, read from the database session store. Logout is only
 * logged when someone clicks ออกจากระบบ, so each account's last activity is shown as well.
 */
class LoginLogController extends Controller
{
    public const ONLINE_MINUTES = 5;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(array_keys(LoginLog::EVENTS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = LoginLog::query()
            ->with('user:id,name')
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w
                ->where('email', 'like', "%{$text}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$text}%"))))
            ->when($filters['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn (LoginLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name,
                'email' => $log->email,
                'event' => $log->event,
                'eventLabel' => LoginLog::EVENTS[$log->event] ?? $log->event,
                'ip' => $log->ip_address,
                'device' => self::device($log->user_agent),
            ]);

        return Inertia::render('admin/LoginLogs', [
            'logs' => $logs,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'event' => $filters['event'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'events' => collect(LoginLog::EVENTS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'online' => self::online(),
            'onlineMinutes' => self::ONLINE_MINUTES,
            'sessionsTracked' => config('session.driver') === 'database',
        ]);
    }

    /**
     * Logged-in users with a session active in the last ONLINE_MINUTES, newest first. One row per user (a person
     * on two devices counts once), with how many sessions they have.
     *
     * @return list<array<string, mixed>>
     */
    public static function online(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }
        $since = now()->subMinutes(self::ONLINE_MINUTES)->getTimestamp();
        $sessions = DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $since)
            ->orderByDesc('last_activity')
            ->get(['user_id', 'ip_address', 'user_agent', 'last_activity']);
        $users = User::whereIn('id', $sessions->pluck('user_id')->unique())->get(['id', 'name', 'role'])->keyBy('id');

        return $sessions->groupBy('user_id')->map(function ($rows, $userId) use ($users) {
            $latest = $rows->first();
            $user = $users->get($userId);

            return [
                'id' => (int) $userId,
                'name' => $user?->name ?? "#{$userId}",
                'role' => $user?->role?->label(),
                'sessions' => $rows->count(),
                'ip' => $latest->ip_address,
                'device' => self::device($latest->user_agent),
                'lastActivity' => Carbon::createFromTimestamp($latest->last_activity)->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * A short, readable device name from a user agent ("Chrome · Windows"). Only for display.
     */
    private static function device(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return implode(' · ', array_filter([$browser, $os])) ?: mb_substr($userAgent, 0, 40);
    }
}
