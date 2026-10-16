<?php

namespace Aurora\Filament;

use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;

/**
 * The menu builder, with its location names ("Main navigation", "Footer") in the person's
 * language. They are registered with the panel, before anyone's language is known, so they
 * are translated each time they are read instead.
 */
class MenuBuilderPlugin extends FilamentMenuBuilderPlugin
{
    /**
     * @return array<string, string>
     */
    public function getLocations(): array
    {
        return array_map(fn (string $label): string => __($label), parent::getLocations());
    }
}
