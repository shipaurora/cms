<?php

namespace Aurora\Livewire;

use Aurora\Models\User;
use Aurora\Support\Passkeys;
use Illuminate\View\View;

/**
 * Right after signing in, for people without a passkey: an offer to add one, with why it
 * helps. Not on someone's first sign-in; after that, shown once per sign-in (and only where the browser can make passkeys: HTTPS and
 * WebAuthn), until they add one or tick "Don't show this again", which is kept on their
 * user record. Adding one works as on the profile page (ManagePasskeys).
 */
class PasskeyPrompt extends ManagePasskeys
{
    /**
     * Set on signing in, from the second time on (AuroraServiceProvider), taken by the first admin page after it.
     */
    public const SESSION_KEY = 'cms.offer-passkey';

    public static function shouldOffer(): bool
    {
        $user = auth()->user();

        // Sent to add a passkey or an authenticator app first: no offer on top, nor after it.
        if (request()->routeIs('filament.admin.auth.*')) {
            session()->forget(static::SESSION_KEY);

            return false;
        }

        if (! $user instanceof User || ! Passkeys::available() || ! session()->pull(static::SESSION_KEY, false)) {
            return false;
        }

        return $user->passkey_prompt_dismissed_at === null && ! $user->passkeys()->exists();
    }

    /**
     * "Not now" or the dialog closed; with "Don't show this again" ticked, for good.
     */
    public function dismiss(bool $forever = false): void
    {
        if ($forever) {
            $this->currentUser()->forceFill(['passkey_prompt_dismissed_at' => now()])->save();
        }

        $this->skipRender();
    }

    public function render(): View
    {
        return view('aurora::livewire.passkey-prompt', [
            'suggestedName' => $this->suggestedName(),
        ]);
    }
}
