<?php

namespace Aurora\Http\Middleware;

use Aurora\Enums\ApiScope;
use Aurora\Support\PublicApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A REST endpoint the request's API key needs a scope for (RequireApiScope:pages.list).
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $scope = ApiScope::from($scope);

        if (! PublicApi::permits($request, $scope)) {
            return response()->json(['message' => "This API key can't do this: it needs the \"{$scope->value}\" scope."], 403);
        }

        return $next($request);
    }
}
