<?php

namespace App\Models;

use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
    ];

    /** Audit trail (TOR คุณลักษณะ 2.1-6). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('communities')->logOnly(['code', 'name'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
