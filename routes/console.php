<?php

use App\Backup\BackupService;
use App\Models\Backup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// สำรองข้อมูล (TOR 4.4-5). `php artisan backup:run --type=database|files|all`. Runs from cron when the host allows
// it; otherwise an admin uses the สำรองข้อมูล page (a red banner appears after config('backup.overdue_days')).
Artisan::command('backup:run {--type=all : database, files or all}', function () {
    $types = $this->option('type') === 'all' ? array_keys(Backup::TYPES) : [$this->option('type')];
    foreach ($types as $type) {
        if (! isset(Backup::TYPES[$type])) {
            $this->error("Unknown type {$type}");

            return 1;
        }
        $backup = BackupService::run($type, 'scheduled');
        $this->line("{$type}: {$backup->status} {$backup->message}");
    }

    return 0;
})->purpose('Back up the database and/or the kept import files');

// Needs one cron entry on the host: * * * * * php /path/to/artisan schedule:run
Schedule::command('backup:run --type=database')->dailyAt('02:00');
Schedule::command('backup:run --type=files')->weeklyOn(0, '03:00');
