<?php

namespace Aurora\Http\Middleware;

use Aurora\Enums\Api;
use Aurora\Support\ContentLocale;
use Aurora\Support\PublicApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One of the website's APIs (AllowApi:rest, AllowApi:graphql), when it's switched on and the
 * request may read it (Aurora\Support\PublicApi); in the language asked for with ?locale=.
 */
class AllowApi
{
    public function handle(Request $request, Closure $next, string $api): Response
    {
        $api = Api::from($api);

        if (! PublicApi::enabled($api)) {
            abort(404);
        }

        if (! PublicApi::allows($api, $request)) {
            $message = 'A valid API key is needed: Authorization: Bearer <key>.';

            // Each in its own shape: GraphQL clients read "errors".
            return response()->json($api === Api::Graphql ? ['errors' => [['message' => $message]]] : ['message' => $message], 401);
        }

        if (is_string($locale = $request->query('locale')) && array_key_exists($locale, ContentLocale::all())) {
            ContentLocale::set($locale);
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
