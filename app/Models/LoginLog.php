<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One login, logout or failed login (ประวัติการเข้าใช้งาน, TOR คุณลักษณะ 3-4). Written by the auth event listeners
 * in AppServiceProvider; never edited.
 */
class LoginLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENTS = [
        'login' => 'เข้าสู่ระบบ',
        'logout' => 'ออกจากระบบ',
        'failed' => 'เข้าสู่ระบบไม่สำเร็จ',
    ];

    protected $fillable = ['user_id', 'email', 'event', 'ip_address', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $event, ?User $user, ?string $email = null): void
    {
        $request = request();
        static::query()->create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'event' => $event,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ]);
    }
}
