<?php

namespace Aurora\Support;

use Aurora\Models\Site;
use Throwable;

/**
 * The site being read or worked on: on the website the one whose domain was asked for (or
 * the default), in the admin the one picked (Filament's tenant), and the default site
 * everywhere else (the console, AI apps). Pages, menus, redirects and site settings
 * belong to it (Aurora\Models\Concerns\BelongsToSite).
 *
 * Kept in the container, so it lasts one request (or test) and never leaks into the next.
 */
class CurrentSite
{
    protected const KEY = 'cms.current-site';

    protected const DEFAULT_KEY = 'cms.default-site';

    public static function get(): ?Site
    {
        return (app()->bound(self::KEY) ? app(self::KEY) : null) ?? static::default();
    }

    /**
     * An address on the site being worked on (or shown): its domain, else APP_URL.
     */
    public static function url(string $path = '/'): string
    {
        return static::get()?->url($path) ?? rtrim(Site::baseUrl(null).'/'.ltrim($path, '/'), '/');
    }

    public static function id(): ?string
    {
        return static::get()?->getKey();
    }

    public static function set(?Site $site): void
    {
        app()->instance(self::KEY, $site);
    }

    /**
     * Run something for another site, then switch back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?Site $site, callable $callback): mixed
    {
        $previous = app()->bound(self::KEY) ? app(self::KEY) : null;
        static::set($site);

        try {
            return $callback();
        } finally {
            app()->instance(self::KEY, $previous);
        }
    }

    public static function default(): ?Site
    {
        if (! app()->bound(self::DEFAULT_KEY)) {
            try {
                $site = Site::default();
            } catch (Throwable) {
                // No sites table yet (installing, migrating).
                return null;
            }

            // None yet: nothing to keep, and asked again next time. (The container doesn't
            // count a null it was given as bound, so keeping one breaks the next lookup.)
            if ($site === null) {
                return null;
            }

            app()->instance(self::DEFAULT_KEY, $site);
        }

        return app(self::DEFAULT_KEY);
    }

    /**
     * The site a host belongs to: one with that domain, else the default site. A site
     * without a domain is only reached through the admin (and previews).
     */
    public static function forHost(string $host): ?Site
    {
        return Site::query()->where('domain', strtolower($host))->first() ?? static::default();
    }

    /**
     * Sites changed: look them up again.
     */
    public static function forget(): void
    {
        app()->forgetInstance(self::DEFAULT_KEY);

        if (app()->bound(self::KEY) && ($current = app(self::KEY)) instanceof Site) {
            app()->instance(self::KEY, $current->fresh());
        }
    }
}
