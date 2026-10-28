<?php

namespace Aurora\Support;

use Datlechin\FilamentMenuBuilder\Contracts\MenuPanel;

/**
 * The website's own part in its menus, from a service provider: panels in the menu builder
 * beside Pages (its events, its own routes), and the paths that exist in every language
 * under the language's prefix, so a menu link to one goes to the language being shown.
 *
 *     Menus::panels([
 *         ModelMenuPanel::make('Events')->model(Event::class),
 *         StaticMenuPanel::make('Sections')->add('Events', '/events')->add('Albums', '/albums'),
 *     ]);
 *     Menus::localized(['/events', '/albums']);   // "/events" is /en/events in English
 */
class Menus
{
    /**
     * @var array<int, MenuPanel>
     */
    protected static array $panels = [];

    /**
     * @var array<int, string>
     */
    protected static array $localized = [];

    /**
     * Panels of the website's own for the menu editor, from a service provider's register()
     * method: the menu editor gets its panels when the admin panel is built, before the
     * app's own boot() runs.
     *
     * @param  array<int, MenuPanel>  $panels
     */
    public static function panels(array $panels): void
    {
        static::$panels = [...static::$panels, ...$panels];
    }

    /**
     * @return array<int, MenuPanel>
     */
    public static function registeredPanels(): array
    {
        return static::$panels;
    }

    /**
     * Paths (and what's under them) that each language has under its prefix: a link to one,
     * written in the default language's form, gets the language being shown's prefix.
     *
     * @param  array<int, string>  $paths
     */
    public static function localized(array $paths): void
    {
        static::$localized = array_values(array_unique([...static::$localized, ...array_map(fn (string $path): string => '/'.trim($path, '/'), $paths)]));
    }

    public static function forgetLocalized(): void
    {
        static::$localized = [];
    }

    /**
     * A menu link in the language being shown: one to a localized path gets its prefix.
     */
    public static function localize(?string $url): ?string
    {
        if ($url === null || ! str_starts_with($url, '/') || str_starts_with($url, '//') || ($prefix = ContentLocale::prefix()) === '') {
            return $url;
        }

        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');

        foreach (static::$localized as $localized) {
            if ($path === $localized || str_starts_with($path, rtrim($localized, '/').'/')) {
                return $prefix.$url;
            }
        }

        return $url;
    }
}
