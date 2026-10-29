<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\User;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login()
    {
        $response = $this->get('/communities');

        $response->assertRedirect('/login');
    }

    public function test_staff_cannot_manage_communities()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)->get('/communities')->assertStatus(403);
        $this->actingAs($staff)->post('/communities', ['code' => 'R033', 'name' => 'ใหม่'])->assertStatus(403);
    }

    public function test_members_cannot_manage_communities()
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)->get('/communities')->assertStatus(403);
    }

    public function test_admin_can_view_the_community_list()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->seed(CommunitySeeder::class);

        $response = $this->actingAs($admin)->get('/communities');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('admin/Communities')
            ->has('communities', 32)
            ->where('communities.0.code', 'R001')
            ->where('communities.0.name', 'ศรีสวัสดิ์ 2'));
    }

    public function test_seeder_is_idempotent_and_uses_client_codes()
    {
        $this->seed(CommunitySeeder::class);
        $this->seed(CommunitySeeder::class);

        $this->assertSame(32, Community::count());
        $this->assertDatabaseHas('communities', ['code' => 'R031', 'name' => 'ตักศิลา 1']);
    }

    public function test_admin_can_create_a_community()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->post('/communities', [
            'code' => 'r033',
            'name' => 'ชุมชนทดสอบใหม่',
        ]);

        $response->assertSessionHasNoErrors();
        // Code is upper-cased and the "ชุมชน" prefix stripped before saving.
        $this->assertDatabaseHas('communities', ['code' => 'R033', 'name' => 'ทดสอบใหม่']);
    }

    public function test_success_message_is_shared_for_the_toast()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)
            ->from('/communities')
            ->followingRedirects()
            ->post('/communities', ['code' => 'R033', 'name' => 'ทดสอบ']);

        $response->assertInertia(fn ($page) => $page->where('flash.status', 'เพิ่มชุมชนเรียบร้อยแล้ว'));
    }

    public function test_code_must_match_the_client_format()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach (['33', 'R33', 'C033', 'R0333'] as $code) {
            $this->actingAs($admin)
                ->post('/communities', ['code' => $code, 'name' => 'ทดสอบ '.$code])
                ->assertSessionHasErrors('code');
        }

        $this->assertSame(0, Community::count());
    }

    public function test_code_and_name_must_be_unique()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Community::create(['code' => 'R001', 'name' => 'ศรีสวัสดิ์ 2']);

        $this->actingAs($admin)
            ->post('/communities', ['code' => 'R001', 'name' => 'อื่น'])
            ->assertSessionHasErrors('code');

        $this->actingAs($admin)
            ->post('/communities', ['code' => 'R099', 'name' => 'ศรีสวัสดิ์ 2'])
            ->assertSessionHasErrors('name');
    }

    public function test_admin_can_update_a_community_keeping_its_own_code()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $community = Community::create(['code' => 'R002', 'name' => 'ตักสิลา 2']);

        $response = $this->actingAs($admin)->put("/communities/{$community->id}", [
            'code' => 'R002',
            'name' => 'ตักศิลา 2',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('ตักศิลา 2', $community->fresh()->name);
    }

    public function test_code_cannot_be_changed_on_update()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $community = Community::create(['code' => 'R002', 'name' => 'ตักศิลา 2']);

        $response = $this->actingAs($admin)->put("/communities/{$community->id}", [
            'code' => 'R099',
            'name' => 'ตักศิลา 2 ใหม่',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('R002', $community->fresh()->code);
        $this->assertSame('ตักศิลา 2 ใหม่', $community->fresh()->name);
    }

    public function test_admin_can_delete_a_community()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $community = Community::factory()->create();

        $this->actingAs($admin)->delete("/communities/{$community->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($community);
    }
}
