<?php

namespace Aurora\Models\Concerns;

use Aurora\Models\Site;
use Aurora\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For content that belongs to one site (pages, menus, redirects, forms): queries only see the
 * current site's (CurrentSite), and new records join it. With only the default site, this
 * changes nothing.
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope('site', function (Builder $query): void {
            if (($siteId = CurrentSite::id()) !== null) {
                $query->where($query->getModel()->qualifyColumn('site_id'), $siteId);
            }
        });

        static::creating(function (self $model): void {
            $model->site_id ??= CurrentSite::id();
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Across every site (managing sites, previews by id).
     */
    public static function acrossSites(): Builder
    {
        return static::query()->withoutGlobalScope('site');
    }
}
