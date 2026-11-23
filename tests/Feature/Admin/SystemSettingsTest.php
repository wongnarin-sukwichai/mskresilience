<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_opens_the_settings_page()
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->get('/system-settings')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/system-settings')
            ->assertInertia(fn ($page) => $page->component('admin/SystemSettings')->has('settings', count(Settings::definitions())));
    }

    public function test_defaults_show_tor_badges_and_the_home_page_notice()
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('showTorRefs', true));
        auth()->logout();

        $this->get('/')->assertInertia(fn ($page) => $page->where('mockNotice', Settings::DEFAULT_MOCK_NOTICE));
    }

    public function test_admin_turns_them_off_and_the_change_is_audited()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->put('/system-settings', [
            'show_tor_refs' => false,
            'welcome_mock_notice' => false,
            'welcome_mock_notice_text' => '',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('showTorRefs', false));
        auth()->logout();
        $this->get('/')->assertInertia(fn ($page) => $page->where('mockNotice', null));

        // Two changed switches are logged; the emptied text stays at its default, so it isn't a change.
        $this->assertSame(2, Activity::where('log_name', 'settings')->count());
        $this->assertSame($admin->id, Activity::where('log_name', 'settings')->first()->causer_id);
    }

    public function test_guests_never_get_tor_badges()
    {
        $this->get('/')->assertInertia(fn ($page) => $page->where('showTorRefs', false));
    }
}
