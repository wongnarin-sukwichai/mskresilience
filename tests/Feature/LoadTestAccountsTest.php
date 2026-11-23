<?php

namespace Tests\Feature;

use App\Console\Commands\LoadTestAccounts;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** loadtest:accounts makes view-only, verified ทดสอบ accounts that `loadtest` can log in with, and removes them. */
class LoadTestAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_verified_view_only_accounts_that_can_log_in_and_deletes_only_them()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->artisan('loadtest:accounts', ['count' => 3, '--password' => 'Secret12345'])->assertSuccessful();
        $this->assertSame(['test01@loadtest.local', 'test02@loadtest.local', 'test03@loadtest.local'],
            User::where('email', 'like', '%@loadtest.local')->orderBy('email')->pluck('email')->all());
        $user = User::where('email', LoadTestAccounts::email(2))->first();
        $this->assertSame('ทดสอบ02', $user->name);
        $this->assertSame(UserRole::Member, $user->role);
        $this->assertNotNull($user->email_verified_at);

        $this->post('/login', ['email' => LoadTestAccounts::email(2), 'password' => 'Secret12345'])->assertRedirect(route('dashboard'));
        $this->get('/executive')->assertOk();

        // Running again keeps the same accounts (new password), no duplicates.
        $this->artisan('loadtest:accounts', ['count' => 3, '--password' => 'Other12345'])->assertSuccessful();
        $this->assertSame(3, User::where('email', 'like', '%@loadtest.local')->count());

        $this->artisan('loadtest:accounts', ['--delete' => true])->assertSuccessful();
        $this->assertSame(0, User::where('email', 'like', '%@loadtest.local')->count());
        $this->assertModelExists($admin);
    }
}
