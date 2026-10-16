<?php

namespace Aurora\Http\Middleware;

use Aurora\Filament\Pages\Auth\SetUpRequiredPasskey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While Settings → Security requires passkeys, people without one are sent to add one
 * (SetUpRequiredPasskey) before anything else. Only page visits are redirected: the
 * set-up pages' own requests, and signing out, go through.
 */
class RequirePasskey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! $request->isMethod('GET')
            || $request->hasHeader('X-Livewire')
            || $request->routeIs(SetUpRequiredPasskey::ROUTE_NAME, 'filament.admin.auth.*')
            || ! SetUpRequiredPasskey::isRequired()
            || $request->user()?->passkeys()->exists() !== false
        ) {
            return $next($request);
        }

        return redirect()->guest(route(SetUpRequiredPasskey::ROUTE_NAME));
    }
}
