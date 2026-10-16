<?php

namespace Aurora\Filament\Pages\Auth;

use Aurora\Livewire\ManagePasskeys;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Aurora\Support\Passkeys;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

/**
 * Where people without a passkey are sent while Settings → Security requires one
 * (RequirePasskey), laid out like Filament's page for a required authenticator app.
 */
class SetUpRequiredPasskey extends SimplePage
{
    public const REQUIRED_SETTING = 'security.require_passkey';

    public const ROUTE_NAME = 'filament.admin.auth.passkeys.set-up-required';

    public static function isRequired(): bool
    {
        return Passkeys::available() && Setting::get(self::REQUIRED_SETTING) === true;
    }

    public function mount(): void
    {
        if (! static::isRequired() || $this->hasPasskey()) {
            redirect()->intended(Filament::getUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('Add a passkey');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Add a passkey');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Signing in to :cms needs a passkey: your fingerprint, face, screen lock or a security key. Add one to continue.', ['cms' => config('aurora.name')]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->compact()
                ->secondary()
                ->schema([
                    Livewire::make(ManagePasskeys::class)->key('passkeys'),
                ]),
            Actions::make([
                Action::make('continue')
                    ->label(__('filament-panels::auth/multi-factor/pages/set-up-required-multi-factor-authentication.actions.continue.label'))
                    ->action(fn () => redirect()->intended(Filament::getUrl()))
                    ->visible(fn (): bool => $this->hasPasskey()),
            ])->fullWidth(),
        ]);
    }

    /**
     * A passkey was added or revoked below: show or hide "Continue".
     */
    #[On('passkeys-changed')]
    public function refreshPasskeys(): void
    {
        //
    }

    protected function hasPasskey(): bool
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->passkeys()->exists();
    }
}
