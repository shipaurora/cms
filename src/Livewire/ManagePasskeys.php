<?php

namespace Aurora\Livewire;

use Aurora\Filament\Pages\Auth\SetUpRequiredPasskey;
use Aurora\Models\Passkey;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\LaravelPasskeys\Livewire\PasskeysComponent;

/**
 * Your passkeys, on your own profile: add one (the browser or OS then asks for Touch ID,
 * Face ID, Windows Hello, a phone or a security key), see when each was last used, and
 * revoke the ones you no longer trust.
 *
 * The WebAuthn ceremony itself is spatie/laravel-passkeys; the browser half lives in
 * resources/js/admin/passkeys.js.
 */
class ManagePasskeys extends PasskeysComponent implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * The options for a new passkey. Fetched when the "Add passkey" form opens, so that the
     * "Create passkey" click can start the browser prompt straight away: Safari only shows
     * it in direct response to a click, not after waiting on the server.
     *
     * @return array<string, mixed>
     */
    public function registrationOptions(): array
    {
        return json_decode($this->generatePasskeyOptions(), true);
    }

    /**
     * The browser created a passkey; verify it against the options above and keep it.
     */
    public function createPasskey(string $name, string $passkey): void
    {
        $this->name = trim($name);

        if ($this->name === '' || mb_strlen($this->name) > 255) {
            $this->passkeyFailed(__('Give the passkey a name of up to 255 characters.'));

            return;
        }

        $this->storePasskey($passkey);
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Revoke'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->link()
            ->disabled(fn (): bool => SetUpRequiredPasskey::isRequired() && $this->currentUser()->passkeys()->count() === 1)
            ->tooltip(fn (Action $action): ?string => $action->isDisabled() ? __('Passkeys are required: add another before revoking this one.') : null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('Revoke “:name”?', ['name' => $this->findPasskey($arguments['passkey'] ?? null)?->name ?? __('passkey')]))
            ->modalDescription(__('You can no longer sign in with it. The passkey may stay saved on the device or in the password manager; remove it there too.'))
            ->modalSubmitActionLabel(__('Revoke'))
            ->action(function (array $arguments): void {
                $this->deletePasskey($arguments['passkey']);
                $this->dispatch('passkeys-changed');

                Notification::make()->title(__('Passkey revoked'))->success()->send();
            });
    }

    public function storePasskey(string $passkey): void
    {
        try {
            parent::storePasskey($passkey);
        } catch (ValidationException) {
            $this->passkeyFailed(__('The passkey couldn’t be verified. Please try again.'));

            return;
        }

        $this->dispatch('passkeys-changed');

        Notification::make()
            ->title(__('Passkey added'))
            ->body(__('Next time, choose “Sign in with a passkey” on the login page.'))
            ->success()
            ->send();
    }

    /**
     * Called from the browser when the passkey prompt is cancelled or fails.
     */
    public function passkeyFailed(string $message): void
    {
        session()->forget('passkey-registration-options');

        Notification::make()->title(__('No passkey was added'))->body($message)->danger()->send();
    }

    /**
     * @return Collection<int, Passkey>
     */
    public function passkeys(): Collection
    {
        return $this->currentUser()->passkeys()->latest()->get();
    }

    public function render(): View
    {
        return view('aurora::livewire.manage-passkeys', [
            'passkeys' => $this->passkeys(),
            'suggestedName' => $this->suggestedName(),
        ]);
    }

    protected function findPasskey(?string $id): ?Passkey
    {
        return $id === null ? null : $this->currentUser()->passkeys()->find($id);
    }

    /**
     * "Safari on Mac" and the like, from the browser that is adding the passkey.
     */
    protected function suggestedName(): string
    {
        $agent = (string) request()->userAgent();

        $device = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        return match (true) {
            $device && $browser => "{$browser} on {$device}",
            $device !== null => $device,
            default => 'My passkey',
        };
    }
}
