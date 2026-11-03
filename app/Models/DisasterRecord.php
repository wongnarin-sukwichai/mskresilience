<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One community's งานป้องกันภัย data for one ASSESSMENT_DATE. Null values mean ไม่มีข้อมูล, not 0.
 */
class DisasterRecord extends Model
{
    /**
     * Only written by DisasterSheet (via forceFill), and it has 32 value columns, so they aren't listed here.
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
            'assessment_date' => 'date',
            'area_km2' => 'decimal:2',
            'c4_1_volunteer_per_1000' => 'decimal:2',
            'c6_3_readiness_score' => 'decimal:2',
            'c8_2_response_hours' => 'decimal:2',
            'm_budget_avg_3y' => 'decimal:2',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
