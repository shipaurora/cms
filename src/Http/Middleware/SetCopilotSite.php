<?php

namespace Aurora\Http\Middleware;

use Aurora\Models\Site;
use Aurora\Support\CurrentSite;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The assistant's stream sits outside the admin's tenant routes, so on its own it would
 * not know which site the chat was opened on. A conversation saved that way belongs to
 * no site, and the chat cannot find it again after the editor reloads.
 *
 * The page the chat was opened on is the request's Referer. Its site is the one the
 * conversation, and the tools, should use.
 */
class SetCopilotSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = static::siteFromUrl($request->headers->get('referer'));

        if ($site !== null) {
            Filament::setCurrentPanel('admin');
            Filament::setTenant($site, isQuiet: true);
            CurrentSite::set($site);
        }

        return $next($request);
    }

    /**
     * The site in an admin URL (/admin/{site}/…), when the URL is on this host.
     */
    public static function siteFromUrl(?string $url): ?Site
    {
        if (blank($url) || parse_url($url, PHP_URL_HOST) !== request()->getHost()) {
            return null;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $panel = trim(Filament::getPanel('admin')->getPath(), '/');
        $remainder = $panel === '' ? $path : (str_starts_with($path, $panel.'/') ? trim(substr($path, strlen($panel)), '/') : null);

        if ($remainder === null || $remainder === '') {
            return null;
        }

        return Site::query()->where('handle', explode('/', $remainder)[0])->first();
    }
}
