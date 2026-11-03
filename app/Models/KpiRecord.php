<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One community's งาน KPI data for one ASSESSMENT_DATE: how many in each vulnerable group were reached. The
 * group sizes themselves come from welfare_records / health_records (spec v5 §5.8). Null = ไม่มีข้อมูล, not 0.
 */
class KpiRecord extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'community_id',
        'assessment_date',
        'kpi_low_income_exit_verified_count',
        'kpi_low_income_followup_count',
        'kpi_elderly_plan_count',
        'kpi_disabled_plan_count',
        'kpi_child_plan_count',
        'kpi_medical_plan_ready_count',
        'kpi_bedridden_plan_ready_count',
        'kpi_assisted_evac_ready_count',
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
