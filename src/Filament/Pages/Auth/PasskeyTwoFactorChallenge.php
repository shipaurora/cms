<?php

namespace Aurora\Filament\Pages\Auth;

use Aurora\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * After a passkey, for people with an authenticator app: its code (or a recovery code)
 * before they are signed in, as after a password. The passkey's check is kept in the
 * session for a few minutes (AuthenticateWithPasskeyController); nobody is signed in yet.
 */
class PasskeyTwoFactorChallenge extends SimplePage
{
    public const ROUTE_NAME = 'filament.admin.auth.passkey-challenge';

    protected const SESSION_KEY = 'cms.passkey-awaiting-code';

    protected const MINUTES_TO_ENTER_CODE = 5;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The passkey checked out: wait for the code before signing this person in.
     */
    public static function await(Authenticatable $user, bool $remember): void
    {
        session()->put(self::SESSION_KEY, [
            'identifier' => $user->getAuthIdentifier(),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::MINUTES_TO_ENTER_CODE)->getTimestamp(),
        ]);
    }

    public function mount(): void
    {
        if ($this->awaitingUser() === null) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Enter your code');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Enter your code');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Your passkey is fine. Now enter the code from your authenticator app.');
    }

    public function form(Schema $schema): Schema
    {
        $user = $this->awaitingUser();

        return $schema
            ->statePath('data')
            ->components($user ? Filament::getMultiFactorAuthenticationProviders()['app']->getChallengeFormComponents($user) : []);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('authenticate')
                ->footer([
                    Actions::make([
                        Action::make('authenticate')->label(__('filament-panels::auth/pages/login.multi_factor.form.actions.authenticate.label'))->submit('authenticate'),
                        Action::make('cancel')->label(__('Use another account'))->link()->color('gray')->url(Filament::getLoginUrl()),
                    ])->fullWidth(),
                ]),
        ]);
    }

    public function authenticate(): void
    {
        $user = $this->awaitingUser();

        if ($user === null) {
            $this->redirect(Filament::getLoginUrl());

            return;
        }

        $rateLimitKey = 'cms:passkey-challenge:'.$user->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 5)) {
            throw ValidationException::withMessages(['data.code' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($rateLimitKey)])]);
        }

        RateLimiter::hit($rateLimitKey);

        $this->form->validate();

        $remember = session()->pull(self::SESSION_KEY)['remember'] ?? false;
        RateLimiter::clear($rateLimitKey);

        Filament::auth()->login($user, $remember);
        session()->regenerate();

        $this->redirect(redirect()->intended(Filament::getUrl())->getTargetUrl());
    }

    protected function awaitingUser(): ?User
    {
        $awaiting = session()->get(self::SESSION_KEY);

        if (! is_array($awaiting) || now()->getTimestamp() > ($awaiting['expires_at'] ?? 0)) {
            return null;
        }

        $user = User::query()->find($awaiting['identifier']);

        return $user?->canAccessPanel(Filament::getPanel('admin')) ? $user : null;
    }
}
