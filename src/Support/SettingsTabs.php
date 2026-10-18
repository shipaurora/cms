<?php

namespace Aurora\Support;

use Closure;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Settings a website adds for its own templates (its header, footer …): a tab in one of the
 * Settings pages (Website, Templates …), or a page of its own in Settings' side menu. Registered from a service provider; the tab's fields are saved with the
 * other settings, on the site being edited when their key is listed in $siteKeys.
 *
 *     SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))->schema([...]), siteKeys: ['footer_note'], page: 'website');
 */
class SettingsTabs
{
    /**
     * @var array<string, array{tab: Closure(): Tab, siteKeys: array<int, string>, page: ?string}>
     */
    protected static array $tabs = [];

    /**
     * Registering a key again replaces that tab.
     *
     * @param  Closure(): Tab  $tab  Built when the Settings page is, so labels are in the reader's language.
     * @param  array<int, string>  $siteKeys  The tab's top-level setting keys that each site keeps for itself.
     * @param  string|null  $page  The Settings page it joins as a tab ('website', 'templates' …), or
     *                             null for a page of its own.
     */
    public static function register(string $key, Closure $tab, array $siteKeys = [], ?string $page = null): void
    {
        static::$tabs[$key] = ['tab' => $tab, 'siteKeys' => $siteKeys, 'page' => $page];
    }

    /**
     * The Settings page each tab joins (null: one of its own), by key.
     *
     * @return array<string, ?string>
     */
    public static function pages(): array
    {
        return array_map(fn (array $registered): ?string => $registered['page'], static::$tabs);
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
