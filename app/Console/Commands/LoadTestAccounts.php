<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * บัญชีทดสอบ "ทดสอบ01…NN" for the concurrent-user acceptance test (TOR 4.4-3, คุณลักษณะ 2.1-3): the same accounts
 * serve the real-people login test and `php artisan loadtest`. Role ผู้บริหาร (member): they can only view.
 * Run it on the server that will be tested. Delete them before go-live (`--delete`, also on the go-live checklist).
 */
class LoadTestAccounts extends Command
{
    protected $signature = 'loadtest:accounts
        {count=60 : how many accounts (ทดสอบ01 … ทดสอบNN)}
        {--password= : one password for all of them (default: a random one, printed once)}
        {--delete : remove every ทดสอบ account instead}';

    protected $description = 'Create (or delete) the ทดสอบ01–NN accounts used for the concurrent-user test';

    public const DOMAIN = 'loadtest.local';

    /** Two digits, so ทดสอบ01…99 sort right; `loadtest` builds the same emails with email(). */
    public static function number(int $i): string
    {
        return str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    }

    public static function email(int $i): string
    {
        return 'test'.self::number($i).'@'.self::DOMAIN;
    }

    public function handle(): int
    {
        if ($this->option('delete')) {
            $n = User::where('email', 'like', '%@'.self::DOMAIN)->delete();
            $this->info("ลบบัญชีทดสอบแล้ว {$n} บัญชี");

            return self::SUCCESS;
        }

        $count = max(1, min(99, (int) $this->argument('count')));
        $password = $this->option('password') ?: Str::password(12, symbols: false);
        for ($i = 1; $i <= $count; $i++) {
            $no = self::number($i);
            $user = User::firstOrNew(['email' => "test{$no}@".self::DOMAIN]);
            $user->forceFill([
                'name' => "ทดสอบ{$no}",
                'password' => $password,
                'role' => UserRole::Member,
                'email_verified_at' => now(),
            ])->save();
        }

        $this->info('พร้อมแล้ว '.$count.' บัญชี: '.self::email(1).' … '.self::email($count));
        $this->line("รหัสผ่าน (ทุกบัญชี): {$password}");
        $this->warn('ลบออกก่อนใช้งานจริง: php artisan loadtest:accounts --delete');

        return self::SUCCESS;
    }
}
