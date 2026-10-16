<?php

namespace Aurora\Models;

use Aurora\Models\Concerns\BelongsToSite;
use Datlechin\FilamentMenuBuilder\Models\Menu as BaseMenu;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Menu extends BaseMenu
{
    use BelongsToSite, HasUuids;

    /**
     * The visible menu in a place (header, footer, legal) on the current site. Not cached as
     * the package does: its cache is per place, not per site, and holds the id as an int,
     * which turns a UUID into 0.
     */
    public static function location(string $location): ?self
    {
        return self::query()
            ->where('is_visible', true)
            ->whereRelation('locations', 'location', $location)
            ->with('menuItems')
            ->first();
    }
}
