<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One community's งานประชาสัมพันธ์ data for one ASSESSMENT_DATE. Null values mean ไม่มีข้อมูล, not 0.
 */
class PublicRelationsRecord extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'community_id',
        'assessment_date',
        'v_warning_access_gap_score',
        'c4_3_risk_comm_score',
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
