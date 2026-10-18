<?php

namespace Aurora\Http\Middleware;

use Aurora\Support\ContentApi;
use Aurora\Support\ContentLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The content API, when it's switched on and the request may read it (Aurora\Support\ContentApi);
 * in the language asked for with ?locale=.
 */
class AllowContentApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ContentApi::enabled()) {
            abort(404);
        }

        if (! ContentApi::allows($request)) {
            return response()->json(['message' => 'A valid API key is needed: Authorization: Bearer <key>.'], 401);
        }

        if (is_string($locale = $request->query('locale')) && array_key_exists($locale, ContentLocale::all())) {
            ContentLocale::set($locale);
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
