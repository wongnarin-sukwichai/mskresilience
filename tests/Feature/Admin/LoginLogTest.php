<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\LoginLogController;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ประวัติการเข้าใช้งาน (TOR คุณลักษณะ 3-4: บันทึกเวลาเข้า/ออก) and the online-now list (คุณลักษณะ 2.1-3).
 */
class LoginLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logout_and_failed_login_are_recorded()
    {
        $user = User::factory()->create(['email' => 'staff@example.com', 'password' => 'password', 'role' => UserRole::Staff]);

        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'x']);
        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'password']);
        $this->post('/logout');

        $logs = LoginLog::orderBy('id')->get();
        $this->assertSame(['failed', 'failed', 'login', 'logout'], $logs->pluck('event')->all());
        $this->assertSame($user->id, $logs[0]->user_id);
        $this->assertNull($logs[1]->user_id);
        $this->assertSame('nobody@example.com', $logs[1]->email);
        $this->assertSame($user->id, $logs[2]->user_id);
        $this->assertNotNull($logs[2]->ip_address);
    }

    public function test_only_admin_sees_the_log_and_it_filters_by_event()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->get('/login-logs')->assertForbidden();

        LoginLog::create(['user_id' => $admin->id, 'email' => $admin->email, 'event' => 'login']);
        LoginLog::create(['email' => 'x@example.com', 'event' => 'failed']);

        $this->actingAs($admin)->get('/login-logs?event=failed')->assertInertia(fn ($page) => $page
            ->component('admin/LoginLogs')
            ->has('logs.data', 1)
            ->where('logs.data.0.eventLabel', 'เข้าสู่ระบบไม่สำเร็จ'));
    }

    public function test_online_counts_each_recently_active_user_once()
    {
        config(['session.driver' => 'database']);
        $a = User::factory()->create(['name' => 'ก', 'role' => UserRole::Admin]);
        $b = User::factory()->create(['name' => 'ข', 'role' => UserRole::Member]);
        $session = fn (string $id, ?int $userId, int $ago) => DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0 (iPad) Safari/604.1',
            'payload' => '', 'last_activity' => now()->subSeconds($ago)->getTimestamp(),
        ]);
        $session('s1', $a->id, 10);
        $session('s2', $a->id, 30);   // same person, second device
        $session('s3', $b->id, 60);
        $session('s4', null, 5);      // guest
        $session('s5', $b->id, (LoginLogController::ONLINE_MINUTES + 1) * 60); // stale, but b is online via s3

        $online = collect(LoginLogController::online())->keyBy('name');
        $this->assertCount(2, $online);
        $this->assertSame(2, $online['ก']['sessions']);
        $this->assertSame('Safari · iPad', $online['ก']['device']);
        $this->assertSame('ผู้บริหาร', $online['ข']['role']);
    }
}
