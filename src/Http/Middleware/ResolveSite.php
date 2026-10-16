<?php

namespace Aurora\Http\Middleware;

use Aurora\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website answers as the site whose domain was asked for, or as the default site on
 * any other address. (In the admin, the site
 * picked there takes over: SetSiteFromTenant.)
 */
class ResolveSite
{
    public function handle(Request $request, Closure $next): Response
    {
        CurrentSite::set(CurrentSite::forHost($request->getHost()));

        return $next($request);
    }
}
