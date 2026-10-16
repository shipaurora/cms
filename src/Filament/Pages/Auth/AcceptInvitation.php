<?php

namespace Aurora\Filament\Pages\Auth;

use Aurora\Models\Invitation;
use Aurora\Models\User;
use Aurora\Support\CmsNotifications;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;

/**
 * Where an invited person joins: they choose a name and a password, and are signed in.
 *
 * Reached through a signed, expiring link (see Invitation::url()), in the same simple
 * layout as the login page.
 */
class AcceptInvitation extends SimplePage
{
    #[Locked]
    public Invitation $invitation;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(Invitation $invitation): void
    {
        $this->invitation = $invitation->load(['role', 'inviter']);

        $this->form->fill(['email' => $invitation->email]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Join '.config('aurora.name');
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->invitation->isUsable() ? __('Join ').config('aurora.name') : __('This invitation can no longer be used');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->invitation->isUsable()) {
            return $this->invitation->isAccepted()
                ? __('It has already been accepted. Sign in with the account you created.')
                : __('It expired on :date. Ask :name to send a new one.', ['date' => $this->invitation->expires_at->isoFormat('LL'), 'name' => $this->invitation->inviter?->name ?? __('whoever invited you')]);
        }

        return __(':name invited you to join as :role.', ['name' => $this->invitation->inviter?->name ?? __('Someone'), 'role' => $this->invitation->role->displayName()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('email')
                    ->label(__('Email'))
                    ->disabled()
                    ->dehydrated(false)
                    ->prefixIcon('phosphor-envelope-simple'),
                TextInput::make('name')
                    ->label(__('Your name'))
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
                TextInput::make('password')
                    ->label(__('Choose a password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label(__('Confirm password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated(false),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->invitation->isUsable()) {
            return $schema->components([
                Actions::make([
                    Action::make('login')->label(__('Go to sign in'))->url(Filament::getLoginUrl())->color('gray'),
                ])->fullWidth(),
            ]);
        }

        return $schema->components([
            Callout::make(__('You are signed in as :name', ['name' => auth()->user()?->name]))
                ->description(__('Joining signs you out of that account and into your new one.'))
                ->warning()
                ->visible(fn (): bool => auth()->check()),
            Text::make(fn (): ?string => filled($this->invitation->message) ? '“'.$this->invitation->message.'”' : null)
                ->visible(filled($this->invitation->message)),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('accept')
                ->footer([
                    Actions::make([
                        Action::make('accept')->label(__('Join ').config('aurora.name'))->submit('accept'),
                    ])->fullWidth(),
                ]),
        ]);
    }

    public function accept(): void
    {
        $invitation = $this->invitation->fresh();

        abort_unless($invitation?->isUsable(), 410, __('This invitation can no longer be used.'));

        $data = $this->form->getState();

        if (User::query()->where('email', $invitation->email)->exists()) {
            Notification::make()->title(__('An account with this email already exists'))->body(__('Sign in instead.'))->danger()->send();

            return;
        }

        $user = DB::transaction(function () use ($invitation, $data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($invitation->role);
            $invitation->forceFill(['accepted_at' => now(), 'user_id' => $user->id])->save();

            return $user;
        });

        CmsNotifications::invitationAccepted($invitation, $user);

        Auth::login($user);
        session()->regenerate();

        Notification::make()->title(__('Welcome, ').str($user->name)->before(' ').'!')->body(__('You are in. Have a look around.'))->success()->send();

        $this->redirect(Filament::getUrl());
    }

    public function hasLogo(): bool
    {
        return true;
    }
}
