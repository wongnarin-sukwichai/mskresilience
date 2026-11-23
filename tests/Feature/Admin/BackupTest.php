<?php

namespace Tests\Feature\Admin;

use App\Backup\BackupService;
use App\Enums\UserRole;
use App\Models\Backup;
use App\Models\Community;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use ZipArchive;

/**
 * สำรองข้อมูล (TOR 4.4-5, คุณลักษณะ 2.1-5: at least monthly, checked by showing the backup log).
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_admin_backs_up_the_database_as_a_gzip_sql_dump()
    {
        Community::create(['code' => 'R001', 'name' => "ตักศิลา 1 'ทดสอบ'"]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/backups', ['type' => 'database'])->assertSessionHas('status');

        $backup = Backup::sole();
        $this->assertSame(['success', 'manual', $admin->id], [$backup->status, $backup->trigger, $backup->user_id]);
        $sql = gzdecode(Storage::disk('local')->get($backup->path));
        $this->assertStringContainsString('CREATE TABLE "communities"', $sql);
        $this->assertStringContainsString("'ตักศิลา 1 ''ทดสอบ'''", $sql);
        // Temporary tables keep their structure only.
        $this->assertStringContainsString('CREATE TABLE "sessions"', $sql);
        $this->assertStringNotContainsString('INSERT INTO "sessions"', $sql);
        $this->assertSame('backup_created', Activity::where('log_name', 'backups')->sole()->event);
    }

    public function test_files_backup_zips_the_kept_import_files_but_not_temporary_ones()
    {
        Storage::disk('local')->put('imports/2026/11/23/a.xlsx', 'x');
        Storage::disk('local')->put('imports/tmp/waiting.xlsx', 'y');

        $backup = BackupService::run('files', 'manual', $this->admin());

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($backup->path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertSame(['imports/2026/11/23/a.xlsx'], $names);
        $this->assertSame('1 ไฟล์', $backup->message);
    }

    public function test_the_log_page_download_and_access()
    {
        $admin = $this->admin();
        $backup = BackupService::run('database', 'manual', $admin);

        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->get('/backups')->assertForbidden();
        $this->actingAs($admin)->get('/backups')->assertInertia(fn ($page) => $page
            ->component('admin/Backups')
            ->has('backups.data', 1)
            ->where('backups.data.0.downloadable', true)
            ->where('daysSinceDatabase', 0));

        $this->actingAs($admin)->get("/backups/{$backup->id}/download")->assertOk()->assertDownload(basename($backup->path));
        $this->assertSame(1, Activity::where('event', 'backup_downloaded')->count());
    }

    public function test_old_backups_beyond_the_kept_number_are_removed_from_disk()
    {
        config(['backup.keep.database' => 2]);
        $runs = [];
        foreach (range(1, 3) as $i) {
            $this->travel(1)->seconds();
            $runs[] = BackupService::run('database', 'scheduled');
        }

        Storage::disk('local')->assertMissing($runs[0]->path);
        Storage::disk('local')->assertExists($runs[2]->path);
        $this->assertNotNull($runs[0]->refresh()->file_deleted_at);
        $this->assertSame(3, Backup::count());
    }

    public function test_admin_sees_the_overdue_banner_until_a_recent_backup_exists()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('backupOverdue', -1));

        BackupService::run('database', 'manual', $admin);
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('backupOverdue', null));

        $this->travel(31)->days();
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('backupOverdue', 31));
        // Staff never get it.
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('backupOverdue', null));
    }

    public function test_the_command_backs_up_both_for_cron()
    {
        $this->assertSame(0, Artisan::call('backup:run'));
        $this->assertSame(['database', 'files'], Backup::orderBy('id')->pluck('type')->all());
        $this->assertSame(['scheduled'], Backup::distinct()->pluck('trigger')->all());
    }
}
