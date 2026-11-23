<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup run (สำรองข้อมูล, TOR 4.4-5). Written by App\Backup\BackupService.
 */
class Backup extends Model
{
    public const TYPES = [
        'database' => 'ฐานข้อมูล',
        'files' => 'ไฟล์นำเข้าต้นฉบับ',
    ];

    protected $fillable = ['type', 'trigger', 'status', 'path', 'size_bytes', 'message', 'user_id', 'started_at', 'finished_at', 'file_deleted_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'file_deleted_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
