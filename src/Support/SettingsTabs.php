<?php

namespace Aurora\Support;

use Closure;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Settings a website adds for its own templates (its header, footer …): a tab in one of the
 * Settings pages (Website, Integrations …), in a page the website adds with page(), or a page
 * of its own in Settings' side menu. Registered from a service provider; the tab's fields are
 * saved with the other settings, on the site being edited when their key is listed in $siteKeys.
 * Keys listed in $secretKeys (an API key, in a SecretInput) are stored encrypted, never sent
 * to the browser, and read with SettingSecrets::get().
 *
 *     SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))->schema([...]), siteKeys: ['footer_note'], page: 'website');
 *     SettingsTabs::page('shop', fn (): string => __('Shop'), 'phosphor-storefront');
 *     SettingsTabs::register('shipping', fn (): Tab => Tab::make(__('Shipping'))->schema([...]), page: 'shop');
 */
class SettingsTabs
{
    /**
     * @var array<string, array{tab: Closure(): Tab, siteKeys: array<int, string>, page: ?string, publicKeys: array<int, string>, secretKeys: array<int, string>}>
     */
    protected static array $tabs = [];

    /**
     * @var array<string, array{label: string|Closure(): string, icon: string, after: string}>
     */
    protected static array $pages = [];

    /**
     * Registering a key again replaces that tab.
     *
     * @param  Closure(): Tab  $tab  Built when the Settings page is, so labels are in the reader's language.
     * @param  array<int, string>  $siteKeys  The tab's top-level setting keys that each site keeps for itself.
     * @param  string|null  $page  The Settings page it joins as a tab ('website', 'templates' …), or
     *                             null for a page of its own.
     * @param  array<int, string>  $publicKeys  The tab's top-level setting keys anyone may read from the
     *                                          website's APIs (Aurora\Support\PublicApi), such as a header's
     *                                          links. None unless named: never list a key, token or address
     *                                          visitors shouldn't see.
     * @param  array<int, string>  $secretKeys  The tab's keys entered with a SecretInput (an API key, a
     *                                          token): stored encrypted, never filled into the form or
     *                                          made public, read with SettingSecrets::get().
     */
    public static function register(string $key, Closure $tab, array $siteKeys = [], ?string $page = null, array $publicKeys = [], array $secretKeys = []): void
    {
        static::$tabs[$key] = ['tab' => $tab, 'siteKeys' => $siteKeys, 'page' => $page, 'publicKeys' => $publicKeys, 'secretKeys' => $secretKeys];
    }

    /**
     * A page of Settings' side menu for the website's own tabs, which join it with
     * register(page: $key): one item for an area of its own (a shop: shipping, payments, taxes)
     * instead of an item per tab. After Website, unless $after names another page.
     *
     * @param  string|Closure(): string  $label  Built when the Settings page is, so in the reader's language.
     */
    public static function page(string $key, string|Closure $label, string $icon = 'phosphor-gear', string $after = 'website'): void
    {
        static::$pages[$key] = ['label' => $label, 'icon' => $icon, 'after' => $after];
    }

    /**
     * The pages added with page(), by key, their labels built.
     *
     * @return array<string, array{label: string, icon: string, after: string}>
     */
    public static function customPages(): array
    {
        return array_map(fn (array $page): array => [...$page, 'label' => value($page['label'])], static::$pages);
    }

    /**
     * Take a page added with page() away again (a test).
     */
    public static function forgetPage(string $key): void
    {
        unset(static::$pages[$key]);
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

    /**
     * The setting keys the tabs let the website's APIs show.
     *
     * @return array<int, string>
     */
    public static function publicKeys(): array
    {
        // A secret is never public, even when a tab names it.
        return array_values(array_diff(array_merge([], ...array_values(array_column(static::$tabs, 'publicKeys'))), static::secretKeys()));
    }

    /**
     * The setting keys the tabs keep encrypted (register(secretKeys:)).
     *
     * @return array<int, string>
     */
    public static function secretKeys(): array
    {
        return array_merge([], ...array_values(array_column(static::$tabs, 'secretKeys')));
    }

    /**
     * The tabs with settings the website's APIs show, built: their fields say what each value
     * is (a page, a file …).
     *
     * @return array<int, Tab>
     */
    public static function publicTabs(): array
    {
        return array_values(array_map(fn (array $registered): Tab => ($registered['tab'])(), array_filter(static::$tabs, fn (array $registered): bool => $registered['publicKeys'] !== [])));
    }
}
