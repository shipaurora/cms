<?php

namespace Aurora\Filament;

use Aurora\Models\NavigationLocation;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;

/**
 * The menu builder, with its locations from the database (NavigationLocation, set up under
 * Menus › More › Manage locations), their names in the person's language. Read each time, so
 * a new location is there straight away.
 */
class MenuBuilderPlugin extends FilamentMenuBuilderPlugin
{
    /**
     * @return array<string, string>
     */
    public function getLocations(): array
    {
        // Before the database is set up (installing), none.
        return rescue(fn (): array => NavigationLocation::options(), [], report: false);
    }
}
