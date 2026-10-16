<?php

namespace Aurora\Http\Controllers;

use Aurora\Filament\Auth\AuthenticatorApps;
use Aurora\Filament\Pages\Auth\PasskeyTwoFactorChallenge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\LaravelPasskeys\Http\Controllers\AuthenticateUsingPasskeyController;

/**
 * "Sign in with a passkey" (spatie/laravel-passkeys), except that people with an
 * authenticator app still enter its code: the passkey stands in for the password, not for
 * the second step. Their sign-in waits for the code on PasskeyTwoFactorChallenge.
 */
class AuthenticateWithPasskeyController extends AuthenticateUsingPasskeyController
{
    protected bool $isAwaitingCode = false;

    protected function logInAuthenticatable(Authenticatable $authenticatable, bool $remember = false): self
    {
        if (! AuthenticatorApps::make()->isEnabled($authenticatable)) {
            return parent::logInAuthenticatable($authenticatable, $remember);
        }

        PasskeyTwoFactorChallenge::await($authenticatable, $remember);
        $this->isAwaitingCode = true;

        return $this;
    }

    protected function validPasskeyResponse(Request $request): RedirectResponse
    {
        return $this->isAwaitingCode
            ? redirect()->route(PasskeyTwoFactorChallenge::ROUTE_NAME)
            : parent::validPasskeyResponse($request);
    }
}
