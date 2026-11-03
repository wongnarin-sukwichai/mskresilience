<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One disaster event (งานป้องกันภัยเหตุการณ์, the Loss & Damage register), identified by the staff-typed
 * EVENT_ID. Null values mean ไม่มีข้อมูล, not 0. Hazards are internal keys (FLOOD/FIRE/STORM/HEAT/PM25);
 * related_hazards is a comma-separated list of them.
 */
class DisasterEvent extends Model
{
    /**
     * Only written by EventSheet (via forceFill), so the many value columns aren't listed here.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_start' => 'date',
            'event_end' => 'date',
            'multi_hazard_flag' => 'boolean',
            'l3_damage_value_thb' => 'decimal:2',
            'f1_event_total_budget_thb' => 'decimal:2',
            'f2_event_response_cost_thb' => 'decimal:2',
            'f3_event_recovery_cost_thb' => 'decimal:2',
            'f4_event_risk_reduction_budget_thb' => 'decimal:2',
            'f5_event_relief_compensation_thb' => 'decimal:2',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
