<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One community's งานสำนักช่าง data for one ASSESSMENT_DATE. Null values mean ไม่มีข้อมูล, not 0.
 */
class EngineeringRecord extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'community_id',
        'assessment_date',
        'v_infra_gap_count_flood',
        'v_infra_gap_count_fire',
        'v_infra_gap_count_storm',
        'v_infra_gap_count_heat',
        'v_infra_gap_count_pm25',
        'c5_1_greenblue_score',
        'c5_2_nbs_score',
        'c5_3_maintenance_score',
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
