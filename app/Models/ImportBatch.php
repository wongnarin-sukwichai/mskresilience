<?php

namespace App\Models;

use App\Enums\WorkGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmed import (history; see the import_batches migration).
 */
class ImportBatch extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'work_group',
        'user_id',
        'original_filename',
        'stored_path',
        'row_count',
        'replaced_count',
        'community_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_group' => WorkGroup::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
