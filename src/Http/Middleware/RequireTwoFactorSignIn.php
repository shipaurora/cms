<?php

namespace Aurora\Http\Middleware;

use Aurora\Filament\Auth\AuthenticatorApps;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * While Settings → Security requires two-factor sign-in, people without an authenticator app
 * are sent to set one up before anything else. Checked on each request, as the setting can
 * change at any time (the panel only decides once, when its routes are registered).
 */
class RequireTwoFactorSignIn extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! AuthenticatorApps::isRequired()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
