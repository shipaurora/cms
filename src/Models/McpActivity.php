<?php

namespace Aurora\Models;

use Aurora\Enums\AiChannel;
use Aurora\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['site_id', 'user_id', 'channel', 'tool', 'summary', 'succeeded', 'subject_type', 'subject_id', 'arguments'])]
class McpActivity extends Model
{
    use BelongsToSite;
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'channel' => AiChannel::class,
            'succeeded' => 'boolean',
            'arguments' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the assistant changed, on whichever site it is.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScope('site');
    }
}
