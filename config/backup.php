<?php

return [
    /*
    | สำรองข้อมูล (TOR 4.4-5, คุณลักษณะ 2.1-5: at least monthly). An admin runs it from the สำรองข้อมูล page, or cron
    | runs `php artisan schedule:run` every minute (routes/console.php: database daily, files weekly). Files go to
    | storage/app/private/backups. Older successful backups past `keep` are deleted (their log rows stay).
    */
    'keep' => [
        'database' => (int) env('BACKUP_KEEP_DATABASE', 30),
        'files' => (int) env('BACKUP_KEEP_FILES', 8),
    ],

    // The admin banner appears once the newest successful database backup is older than this.
    'overdue_days' => (int) env('BACKUP_OVERDUE_DAYS', 30),

    // Tables whose rows are temporary: their structure is backed up, not their data.
    'skip_data' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'],
];
