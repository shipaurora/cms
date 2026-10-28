<?php

namespace Aurora\Filament\Resources\Users\Pages;

use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use STS\FilamentImpersonate\Actions\Impersonate;

class EditUser extends EditRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use SavesFromHeader;

    protected static string $resource = UserResource::class;

    /**
     * This page doubles as everyone's profile ("Profile" in the user menu), so it does not
     * require access to the user list. The record itself is still authorised through
     * UserPolicy::update(): you may edit yourself, or others if you manage users.
     */
    public static function authorizeResourceAccess(): void
    {
        //
    }

    public function getTitle(): string
    {
        return $this->record->is(auth()->user()) ? __('Your profile') : $this->record->name;
    }

    /**
     * Your own profile: the top bar's user menu shows the new name and picture straight away,
     * and a new preferred language or admin theme applies straight away (a reload in it).
     */
    protected function afterSave(): void
    {
        if (! $this->record->is(auth()->user())) {
            return;
        }

        // A new admin theme is a stylesheet in the page's head: a full reload, not a navigation.
        if ($this->record->wasChanged(['locale', 'admin_theme'])) {
            $this->redirect(static::getUrl(['record' => $this->record]), navigate: ! $this->record->wasChanged('admin_theme'));

            return;
        }

        $this->dispatch('refresh-topbar');
    }

    protected function getHeaderActions(): array
    {
        return [
            Impersonate::make()
                ->icon('phosphor-detective-bold')
                ->label(__('View as :name', ['name' => str($this->record->name)->before(' ')]))
                ->record($this->record)
                ->redirectTo(fn (): string => filament()->getUrl()),
            // Someone lost their phone: they add an app again (and must, if it is required).
            Action::make('resetTwoFactor')
                ->label(__('Reset two-factor sign-in'))
                ->icon('phosphor-shield-slash')
                ->color('gray')
                ->visible(fn (): bool => ! $this->record->is(auth()->user()) && $this->record->authenticatorApps()->exists())
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __(':name’s authenticator apps and recovery codes are removed, and they sign in with just their password until they add an app again.', ['name' => $this->record->name]))
                ->action(function (): void {
                    $this->record->saveAppAuthenticationSecret(null);
                    $this->record->saveAppAuthenticationRecoveryCodes(null);

                    Notification::make()->title(__('Two-factor sign-in reset'))->success()->send();
                }),
            DeleteAction::make(),
            $this->headerSaveButton(),
        ];
    }
}
