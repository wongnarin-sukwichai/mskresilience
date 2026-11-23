<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One system setting (key → JSON value). Read and write through App\Support\Settings, which knows the keys and
 * their defaults. Changes go to the audit trail.
 */
class AppSetting extends Model
{
    use LogsActivity;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('settings')->logOnly(['key', 'value'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
