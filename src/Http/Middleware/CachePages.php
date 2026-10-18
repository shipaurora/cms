<?php

namespace Aurora\Http\Middleware;

use Aurora\Support\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The website's pages, served ready-made when they were drawn before (Aurora\Support\PageCache).
 */
class CachePages
{
    public function handle(Request $request, Closure $next): Response
    {
        if (($kept = PageCache::get($request)) !== null) {
            return $kept;
        }

        $response = $next($request);
        PageCache::put($request, $response);

        return $response;
    }
}
