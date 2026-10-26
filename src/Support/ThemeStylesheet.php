<?php

namespace Aurora\Support;

use Filament\Support\Assets\Theme;

/**
 * An admin theme's prebuilt stylesheet as one of Filament's assets, its address versioned by
 * the theme's own release (Themes::version()). Filament versions an asset by its package, and a
 * theme publishes under a name Composer may not know (Borealis keeps its old one, so sites load
 * it from where they always have): the address then only changed with Filament, and browsers and
 * caches kept an old theme long after an update.
 */
class ThemeStylesheet extends Theme
{
    public function getVersion(): string
    {
        $version = Themes::version($this->getId());

        return $version === null ? parent::getVersion() : ltrim($version, 'v');
    }
}
