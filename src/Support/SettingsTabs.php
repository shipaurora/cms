<?php

namespace Aurora\Support;

use Closure;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Settings a website adds for its own templates (its header, footer …), each a section of
 * the Settings page next to the CMS's own (its side menu lists them). Registered from a service provider; the tab's fields are saved with the
 * other settings, on the site being edited when their key is listed in $siteKeys.
 *
 *     SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))->schema([...]), siteKeys: ['footer_note']);
 */
class SettingsTabs
{
    /**
     * @var array<string, array{tab: Closure(): Tab, siteKeys: array<int, string>}>
     */
    protected static array $tabs = [];

    /**
     * Registering a key again replaces that tab.
     *
     * @param  Closure(): Tab  $tab  Built when the Settings page is, so labels are in the reader's language.
     * @param  array<int, string>  $siteKeys  The tab's top-level setting keys that each site keeps for itself.
     */
    public static function register(string $key, Closure $tab, array $siteKeys = []): void
    {
        static::$tabs[$key] = ['tab' => $tab, 'siteKeys' => $siteKeys];
    }

    /**
     * Take a section away again (a site that replaces one of a package's, or a test).
     */
    public static function forget(string $key): void
    {
        unset(static::$tabs[$key]);
    }

    /**
     * By key: each is a section of the Settings page, at ?tab={key}.
     *
     * @return array<string, Tab>
     */
    public static function tabs(): array
    {
        return array_map(fn (array $registered): Tab => ($registered['tab'])(), static::$tabs);
    }

    /**
     * @return array<int, string>
     */
    public static function siteKeys(): array
    {
        return array_merge([], ...array_values(array_column(static::$tabs, 'siteKeys')));
    }
}
