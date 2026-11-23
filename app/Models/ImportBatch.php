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
        'source',
        'status',
        'payload',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    /** Same defaults as the columns, so a just-created batch has them without a refresh. */
    protected $attributes = ['source' => 'file', 'status' => 'active'];

    /** Where the batch came from: an uploaded file, or one change in the web edit form (App\Import\RecordEditor). */
    public const SOURCES = [
        'file' => 'นำเข้าไฟล์',
        'web_add' => 'เพิ่มในเว็บ',
        'web_edit' => 'แก้ไขในเว็บ',
        'web_delete' => 'ลบในเว็บ',
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
            'reviewed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isCancelled();
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /** Whether its data is (or was, until cancelled) in the data tables. */
    public function isApplied(): bool
    {
        return $this->status === 'active';
    }

    public function isWeb(): bool
    {
        return $this->source !== 'file';
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
