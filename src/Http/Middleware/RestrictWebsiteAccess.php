<?php

namespace Aurora\Http\Middleware;

use Aurora\Support\WebsiteAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the website is open only to some addresses (Aurora\Support\WebsiteAccess), everyone
 * else gets the "not open yet" page.
 */
class RestrictWebsiteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! WebsiteAccess::allows($request)) {
            return response()->view('aurora::site-closed', [], 403)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
