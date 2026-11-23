<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI Executive Summary call (App\Ai\SummaryWriter): facts sent, text back, tokens and cost.
 */
class AiSummary extends Model
{
    protected $fillable = ['facts_hash', 'facts', 'text', 'status', 'error', 'model', 'input_tokens', 'output_tokens', 'cost_usd', 'user_id'];

    protected function casts(): array
    {
        return ['facts' => 'array', 'cost_usd' => 'float', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
