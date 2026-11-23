<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A เกณฑ์กลาง weights set. Which data it applies to comes from start_fiscal_year (พ.ศ.), see
 * App\Scoring\CriteriaSets. is_locked is the admin's manual lock; a set in use by data is read-only regardless.
 */
class ScoringCriteriaSet extends Model
{
    use LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'start_fiscal_year',
        'is_locked',
        'weights',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_fiscal_year' => 'integer',
            'is_locked' => 'boolean',
            'weights' => 'array',
        ];
    }

    /** Audit trail (TOR คุณลักษณะ 2.1-6): every change to a set, with old and new weights. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('criteria')
            ->logOnly(['name', 'start_fiscal_year', 'is_locked', 'weights'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
