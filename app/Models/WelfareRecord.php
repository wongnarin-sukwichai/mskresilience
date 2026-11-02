<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One community's งานสวัสดิการสังคม data for one ASSESSMENT_DATE. Null values mean ไม่มีข้อมูล, not 0.
 */
class WelfareRecord extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'community_id',
        'assessment_date',
        'v_elderly_count',
        'v_child_count',
        'v_disabled_count',
        'v_low_income_count',
        'import_batch_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
