<?php

namespace Aurora\Support;

use Aurora\Http\Controllers\PageController;
use Aurora\Models\Asset;
use Aurora\Models\Page;
use Aurora\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Where a link on the website leads, for SeoChecks: one of the site's live pages, a
 * redirect, or nowhere (a page taken down, removed or never made). Links to other websites,
 * e-mail addresses and phone numbers aren't followed.
 */
class InternalLinks
{
    public const OK = 'ok';

    public const REDIRECT = 'redirect';

    public const BROKEN = 'broken';

    public const EXTERNAL = 'external';

    /**
     * @return self::OK|self::REDIRECT|self::BROKEN|self::EXTERNAL
     */
    public static function status(string $href): string
    {
        $path = static::path($href);

        if ($path === null) {
            return self::EXTERNAL;
        }

        if (isset(static::livePaths()[$path])) {
            return self::OK;
        }

        $redirect = Redirect::query()->where('from_path', Redirect::normalisePath($path))->first();

        if ($redirect !== null && filled($redirect->destination())) {
            return self::REDIRECT;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($path));
        } catch (Throwable) {
            return self::BROKEN;
        }

        return match (true) {
            // Not one of the live pages' addresses: an old address of one is sent on to it.
            in_array($route->getActionName(), [PageController::class.'@show', PageController::class.'@home'], true) => static::isOldAddress($path) ? self::REDIRECT : self::BROKEN,
            $route->getName() === 'files.show' => Asset::query()->whereKey($route->parameter('asset'))->exists() ? self::OK : self::BROKEN,
            default => self::OK,
        };
    }

    /**
     * The path of a link to this website ("/about"), or null for one that goes elsewhere.
     */
    protected static function path(string $href): ?string
    {
        $parts = parse_url($href);

        if ($parts === false || isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['host']) && ! in_array(strtolower($parts['host']), static::ownHosts(), true)) {
            return null;
        }

        // Relative to the page ("prices") is rare in a CMS: read as from the site's root.
        return '/'.trim(rawurldecode($parts['path'] ?? ''), '/');
    }

    /**
     * @return array<int, string>
     */
    protected static function ownHosts(): array
    {
        return once(fn (): array => array_values(array_unique(array_filter(array_map('strtolower', [
            (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            (string) parse_url(url('/'), PHP_URL_HOST),
            (string) CurrentSite::get()?->domain,
        ])))));
    }

    /**
     * Every live page's address, in each of its languages.
     *
     * @return array<string, true>
     */
    protected static function livePaths(): array
    {
        return once(function (): array {
            $paths = [];

            foreach (Page::query()->live()->get() as $page) {
                foreach (array_keys(ContentLocale::all()) ?: [ContentLocale::default()] as $locale) {
                    if ($page->isAvailableIn($locale)) {
                        $paths[$page->path($locale)] = true;
                    }
                }
            }

            return $paths;
        });
    }

    /**
     * An address a live page had, or has in another place in the tree: the website sends
     * visitors on from it (PageController::show()).
     */
    protected static function isOldAddress(string $path): bool
    {
        $found = Page::findByPath($path) ?? Page::findByOldPath($path);

        return $found !== null && $found->isLive();
    }
}
