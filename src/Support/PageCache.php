<?php

namespace Aurora\Support;

use Aurora\Models\Page;
use Aurora\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website's pages, kept ready-made for visitors (Settings › Performance), so a page is
 * drawn once rather than on every visit. Only what every visitor sees alike is kept: never
 * for someone signed in, never a page with a form (its CSRF token is the visitor's own), a
 * preview, or one behind a password. Any change to what pages show clears it all
 * (AuroraServiceProvider), and nothing is kept past the next scheduled publish or unpublish.
 */
class PageCache
{
    public const SETTING = 'page_cache';

    /**
     * Bumped to clear: every kept page's key carries it.
     */
    protected const VERSION = 'aurora.page-cache.version';

    public static function enabled(): bool
    {
        return (bool) ((Setting::get(self::SETTING) ?? [])['enabled'] ?? false);
    }

    public static function minutes(): int
    {
        return max(1, (int) ((Setting::get(self::SETTING) ?? [])['minutes'] ?? 60));
    }

    /**
     * The kept page for this visit, if there is one.
     */
    public static function get(Request $request): ?Response
    {
        if (! static::cacheable($request)) {
            return null;
        }

        $kept = Cache::get(static::key($request));

        return $kept === null ? null : response($kept['content'], 200, [...$kept['headers'], 'X-Aurora-Cache' => 'HIT']);
    }

    /**
     * Keep the page just drawn, when every visitor would see it alike.
     */
    public static function put(Request $request, Response $response): void
    {
        $content = (string) $response->getContent();

        if (! static::cacheable($request)
            || $response->getStatusCode() !== 200
            // Marked never to keep (a page behind a password, unlocked for this visitor).
            || str_contains((string) $response->headers->get('Cache-Control'), 'no-store')
            || str_contains($content, 'name="_token"')) {
            return;
        }

        $seconds = static::minutes() * 60;

        // Gone by the next scheduled publish or unpublish, so that change shows on time.
        if (($next = static::nextScheduledChange()) !== null) {
            $seconds = min($seconds, max(1, now()->diffInSeconds($next)));
        }

        Cache::put(static::key($request), [
            'content' => $content,
            'headers' => array_filter(['Content-Type' => $response->headers->get('Content-Type')]),
        ], (int) $seconds);

        $response->headers->set('X-Aurora-Cache', 'MISS');
    }

    /**
     * Clear every kept page.
     */
    public static function flush(): void
    {
        Cache::forever(self::VERSION, (int) Cache::get(self::VERSION, 0) + 1);
    }

    protected static function cacheable(Request $request): bool
    {
        return static::enabled()
            && $request->isMethod('GET')
            && $request->query() === []
            && auth()->guest()
            && ! session()->has('errors');
    }

    protected static function key(Request $request): string
    {
        return 'aurora.page-cache.'.Cache::get(self::VERSION, 0).'.'.sha1($request->getHost().'/'.$request->path());
    }

    protected static function nextScheduledChange(): ?Carbon
    {
        $next = collect([
            Page::acrossSites()->where('published_at', '>', now())->min('published_at'),
            Page::acrossSites()->where('unpublish_at', '>', now())->min('unpublish_at'),
        ])->filter()->min();

        return $next === null ? null : Carbon::parse($next);
    }
}
