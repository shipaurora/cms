<?php

namespace Aurora\Models;

use Aurora\Database\Factories\LayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Once;

/**
 * How pages are drawn on the website, set up under Pages › More › Layouts: a name editors
 * pick by, and the Blade view that draws the page (Aurora\Support\Layouts). Each page picks
 * one; pages without one use the default.
 */
#[Fillable(['name', 'handle', 'description', 'icon', 'view', 'is_default', 'sort'])]
class Layout extends Model
{
    /** @use HasFactory<LayoutFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // One default: making a layout the default takes it from the others.
        static::saved(function (self $layout): void {
            if ($layout->is_default && ($layout->wasRecentlyCreated || $layout->wasChanged('is_default'))) {
                static::query()->whereKeyNot($layout->getKey())->where('is_default', true)->update(['is_default' => false]);
            }

            Once::flush();
        });

        static::deleted(fn () => Once::flush());
    }

    /**
     * Every layout, in order, read once per request.
     *
     * @return Collection<int, Layout>
     */
    public static function cached(): Collection
    {
        return once(fn (): Collection => static::query()->orderBy('sort')->orderBy('name')->get());
    }

    /**
     * What pages without a layout of their own use: the default, else the first.
     */
    public static function default(): ?self
    {
        return static::cached()->firstWhere('is_default', true) ?? static::cached()->first();
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }
}
