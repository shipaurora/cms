<?php

namespace Aurora\Filament\Auth;

use Aurora\Models\AuthenticatorApp;
use Aurora\Models\Setting;
use Aurora\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Two-factor sign-in with authenticator apps (Google Authenticator, 1Password, Authy…).
 * Filament's app authentication, for any number of apps per person: each has its own
 * secret and name, a code from any of them signs in, and a lost phone is removed on its
 * own. Recovery codes are per person. Managed on your profile, and on the page Filament
 * sends people to when Settings → Security requires it.
 */
class AuthenticatorApps extends AppAuthentication
{
    public const REQUIRED_SETTING = 'security.require_two_factor';

    /**
     * Settings → Security: everyone must have an app (they are sent to add one).
     */
    public static function isRequired(): bool
    {
        return Setting::get(self::REQUIRED_SETTING) === true;
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return $user instanceof User && $user->authenticatorApps()->exists();
    }

    /**
     * The apps, each with its own rename and remove, then "Add an authenticator app".
     *
     * @return array<Component>
     */
    public function getManagementSchemaComponents(): array
    {
        return [
            // Listed like your passkeys; each app's rename and remove are its own actions.
            View::make('aurora::filament.users.authenticator-apps')
                ->viewData(fn (): array => ['apps' => $this->currentUser()->authenticatorApps()->get()])
                ->schema(fn (): array => $this->currentUser()->authenticatorApps()->get()->map(fn (AuthenticatorApp $app): Component => Actions::make([
                    $this->renameAction()->arguments(['app' => $app->getKey()]),
                    $this->removeAction()->arguments(['app' => $app->getKey()]),
                ])->key("authenticator-app-{$app->getKey()}"))->all()),
            Actions::make($this->getActions())->key('authenticator-app-actions'),
        ];
    }

    /**
     * Filament's set-up, as "add one more"; its own "disable" is replaced by removing apps.
     *
     * @return array<Action>
     */
    public function getActions(): array
    {
        [$setUp, $regenerateRecoveryCodes] = parent::getActions();

        return [
            $setUp
                ->label(__('Add authenticator app'))
                ->icon('phosphor-plus-bold')
                ->button()
                ->color('gray')
                // Nothing is saved before the last step, so closing it early just cancels.
                ->closeModalByClickingAway()
                ->closeModalByEscaping()
                ->hidden(false),
            $regenerateRecoveryCodes->icon('phosphor-arrows-clockwise')->button()->color('gray'),
        ];
    }

    public function renameAction(): Action
    {
        return Action::make('renameAuthenticatorApp')
            ->label(__('Rename'))
            ->iconButton()
            ->icon('phosphor-pencil-simple')
            ->color('gray')
            ->modalWidth('md')
            ->fillForm(fn (array $arguments): array => ['name' => $this->findApp($arguments)?->name])
            ->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->helperText(__('So you can tell your apps apart, like “Work phone”.')),
            ])
            ->action(fn (array $arguments, array $data) => $this->findApp($arguments)?->update(['name' => $data['name']]));
    }

    /**
     * Removing the last app turns two-factor sign-in off, unless it is required.
     */
    public function removeAction(): Action
    {
        return Action::make('removeAuthenticatorApp')
            ->label(__('Remove'))
            ->iconButton()
            ->icon('phosphor-trash')
            ->color('danger')
            ->disabled(fn (): bool => static::isRequired() && $this->currentUser()->authenticatorApps()->count() === 1)
            ->tooltip(fn (Action $action): ?string => $action->isDisabled() ? __('Two-factor sign-in is required: add another app before removing this one.') : null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('Remove “:name”?', ['name' => $this->findApp($arguments)?->name]))
            ->modalDescription(fn (): string => $this->currentUser()->authenticatorApps()->count() === 1
                ? __('It is your only app: two-factor sign-in is turned off, and your recovery codes stop working.')
                : __('Its codes stop working. Remove the account from the app itself too.'))
            ->modalSubmitActionLabel(__('Remove'))
            ->action(function (array $arguments): void {
                $user = $this->currentUser();
                $this->findApp($arguments)?->delete();

                if (! $this->isEnabled($user)) {
                    $this->saveRecoveryCodes($user, null);
                }

                Notification::make()->title(__('Authenticator app removed'))->success()->send();
            });
    }

    /**
     * Filament's code input, checked against each of the person's apps.
     *
     * @return array<Component>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        [, $recoveryCode] = parent::getChallengeFormComponents($user);
        $isRecoverable = $this->isRecoverable();

        return [
            OneTimeCodeInput::make('code')
                ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.label'))
                ->submitOnCompletion()
                ->belowContent(fn (Get $get): Action => Action::make('useRecoveryCode')
                    ->label(__('filament-panels::auth/multi-factor/app/provider.login_form.code.actions.use_recovery_code.label'))
                    ->link()
                    ->action(fn (Set $set) => $set('useRecoveryCode', true))
                    ->visible(fn (): bool => $isRecoverable && (! $get('useRecoveryCode'))))
                ->validationAttribute(__('filament-panels::auth/multi-factor/app/provider.login_form.code.validation_attribute'))
                ->required(fn (Get $get): bool => (! $isRecoverable) || (! $get('useRecoveryCode')) || blank($get('recoveryCode')))
                ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail) use ($user): void {
                    if (is_string($value) && $this->verifyCodeFromAnyApp($user, $value)) {
                        return;
                    }

                    $fail(__('filament-panels::auth/multi-factor/app/provider.login_form.code.messages.invalid'));
                }),
            $recoveryCode,
        ];
    }

    /**
     * The app the code came from is marked as used.
     */
    public function verifyCodeFromAnyApp(Authenticatable $user, #[SensitiveParameter] string $code): bool
    {
        $app = $user instanceof User
            ? $user->authenticatorApps()->get()->first(fn (AuthenticatorApp $app): bool => $this->verifyCode($code, $app->secret, shouldPreventCodeReuse: true))
            : null;

        $app?->forceFill(['last_used_at' => now()])->save();

        return $app !== null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function findApp(array $arguments): ?AuthenticatorApp
    {
        return $this->currentUser()->authenticatorApps()->find($arguments['app'] ?? null);
    }

    protected function currentUser(): User
    {
        /** @var User */
        return Filament::auth()->user();
    }
}
