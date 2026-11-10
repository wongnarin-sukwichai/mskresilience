<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A เกณฑ์กลาง weights set. Which data it applies to comes from start_fiscal_year (พ.ศ.), see
 * App\Scoring\CriteriaSets. is_locked is the admin's manual lock; a set in use by data is read-only regardless.
 */
class ScoringCriteriaSet extends Model
{
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
}
