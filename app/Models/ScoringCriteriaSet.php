<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoringCriteriaSet extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
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
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
            'weights' => 'array',
        ];
    }
}
