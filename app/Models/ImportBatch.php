<?php

namespace App\Models;

use App\Enums\WorkGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One confirmed import (history; see the import_batches migration). A cancelled batch keeps its row, with
 * cancelled_at/cancelled_by set, and its data is gone from the data table. Separately, an admin may delete the
 * kept file (file_deleted_at/by); the data and the row stay.
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
        'cancelled_at',
        'cancelled_by',
        'file_deleted_at',
        'file_deleted_by',
        'deleted_file_bytes',
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
            'cancelled_at' => 'datetime',
            'file_deleted_at' => 'datetime',
        ];
    }

    public function fileDeletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'file_deleted_by');
    }

    /**
     * Whether an admin deleted the kept original file (the data stays).
     */
    public function isFileDeleted(): bool
    {
        return $this->file_deleted_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * The values this batch overwrote.
     */
    public function replacedRows(): HasMany
    {
        return $this->hasMany(ImportReplacedRow::class);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Ids of the later, still-active batches that overwrote some of this batch's rows. Batches are cancelled
     * newest first: while one of these exists, cancelling this batch would restore values under its rows.
     *
     * @return list<int>
     */
    public function replacedByBatchIds(): array
    {
        return ImportReplacedRow::where('previous_batch_id', $this->id)
            ->whereHas('batch', fn ($q) => $q->whereNull('cancelled_at'))
            ->distinct()->orderBy('import_batch_id')->pluck('import_batch_id')->all();
    }
}
