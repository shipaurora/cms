<?php

namespace Aurora\Models;

use Aurora\Database\Factories\AssetTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;

/**
 * A coloured label on images in the media library. Unlike folders, an image can carry
 * any number of tags. A new tag gets a random colour from COLORS unless one is chosen.
 */
#[Fillable(['name', 'color'])]
class AssetTag extends Model
{
    /** @use HasFactory<AssetTagFactory> */
    use HasFactory, HasUuids;

    /**
     * Colours that stay readable as small dots in both light and dark mode.
     *
     * @var array<int, string>
     */
    public const COLORS = [
        '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#10b981', '#14b8a6',
        '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
    ];

    protected static function booted(): void
    {
        static::creating(function (AssetTag $tag): void {
            $tag->color = filled($tag->color) ? $tag->color : static::randomColor();
        });
    }

    public static function randomColor(): string
    {
        return Arr::random(self::COLORS);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class);
    }
}
