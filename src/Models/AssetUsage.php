<?php

namespace Aurora\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['asset_id', 'usable_type', 'usable_id', 'location'])]
class AssetUsage extends Model
{
    use HasUuids;

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The page or redirect using the asset, on whichever site: the library is shared.
     */
    public function usable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScope('site');
    }
}
