<?php

namespace Aurora\Support;

use Aurora\Aurora;
use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory;

/**
 * Icons on the website: the real Heroicons, under the "hero-" prefix. The "heroicon-" names
 * draw the admin panel's Phosphor icons (see AdminIcons), so the website, and the visual
 * editor showing it, never uses those.
 */
class SiteIcon
{
    public const SET = 'website-heroicons';

    public const PREFIX = 'hero';

    public static function register(Factory $factory): void
    {
        $factory->add(self::SET, [
            'path' => Aurora::packagePath('blade-ui-kit/blade-heroicons', 'resources/svg'),
            'prefix' => self::PREFIX,
        ]);
    }

    /**
     * The icon to draw for a stored icon name, or null when there is none or it no longer
     * exists. Takes what the icon picker stores ("hero-o-sparkles"), as well as names saved
     * before it: a bare Heroicon name ("sparkles", drawn in $style) and
     * admin-style names ("heroicon-o-home") in menu items.
     */
    public static function name(?string $stored, string $style = 'o'): ?string
    {
        if (blank($stored)) {
            return null;
        }

        $candidates = str_starts_with($stored, 'heroicon-')
            ? [self::PREFIX.'-'.substr($stored, strlen('heroicon-'))]
            : [$stored, self::PREFIX."-{$style}-{$stored}"];

        foreach ($candidates as $name) {
            try {
                svg($name);

                return $name;
            } catch (SvgNotFound) {
                continue;
            }
        }

        return null;
    }
}
