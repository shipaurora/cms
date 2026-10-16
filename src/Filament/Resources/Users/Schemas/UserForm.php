<?php

namespace Aurora\Filament\Resources\Users\Schemas;

use Aurora\Filament\Pages\SiteSettings;
use Aurora\Models\Locale;
use Aurora\Models\Role;
use Aurora\Models\Site;
use Aurora\Models\User;
use Aurora\Support\Passkeys;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make()->columnSpan(['lg' => 2])->schema([
                    Section::make(__('Profile'))
                        ->icon('phosphor-user-circle')
                        ->columns(2)
                        ->schema([
                            FileUpload::make('avatar')
                                ->hiddenLabel()
                                ->avatar()
                                ->imageEditor()
                                ->disk('public')
                                ->directory('avatars')
                                ->columnSpanFull()
                                ->alignCenter(),
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('job_title')->label(__('Job title'))->maxLength(255),
                            TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->prefixIcon('phosphor-envelope-simple')->columnSpanFull(),
                            Select::make('locale')
                                ->label(__('Preferred language'))
                                ->helperText(__('The language of the admin panel for this person.'))
                                ->options(fn (): array => Locale::options())
                                ->placeholder(fn (): string => __('Site default (').(Locale::options()[Locale::defaultCode()] ?? Locale::defaultCode()).')')
                                ->prefixIcon('phosphor-translate')
                                ->in(fn (): array => array_keys(Locale::options()))
                                ->columnSpanFull(),
                        ]),
                    Section::make(__('Password'))
                        ->icon('phosphor-lock')
                        ->description(fn (string $operation): ?string => $operation === Operation::Edit->value ? __('Leave empty to keep the current password.') : null)
                        ->columns(2)
                        ->schema([
                            TextInput::make('password')
                                ->password()
                                ->revealable()
                                ->rule('min:8')
                                ->required(fn (string $operation): bool => $operation === Operation::Create->value)
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->confirmed(),
                            TextInput::make('password_confirmation')->label(__('Confirm password'))->password()->revealable()->dehydrated(false),
                        ]),
                    View::make('aurora::filament.users.passkeys')
                        // Your own profile, over HTTPS (browsers only make passkeys there).
                        ->visible(fn (?User $record): bool => Passkeys::available() && ($record?->is(auth()->user()) ?? false)),
                    Section::make(__('Two-factor sign-in'))
                        ->icon('phosphor-shield-check')
                        ->description(__('After your password, enter a code from an authenticator app on your phone. Add as many apps as you like: a code from any of them works.'))
                        ->schema(fn (): array => Filament::getMultiFactorAuthenticationProviders()['app']->getManagementSchemaComponents())
                        ->visible(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                ]),
                Group::make()->columnSpan(['lg' => 1])->schema([
                    Section::make(__('Access'))
                        ->icon('phosphor-shield-check')
                        ->visible(fn (): bool => auth()->user()->can('update User'))
                        ->schema([
                            Toggle::make('is_active')
                                ->label(__('Active'))
                                ->helperText(__('Inactive users cannot sign in or use the AI assistant.'))
                                ->default(true)
                                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                            CheckboxList::make('roles')
                                ->relationship('roles', 'name', fn ($query) => auth()->user()->isSuperAdmin() ? $query : $query->where('name', '!=', User::SUPER_ADMIN))
                                ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->displayName())
                                ->descriptions(fn (): array => Role::query()->get()->mapWithKeys(fn (Role $role): array => [$role->getKey() => $role->displayDescription()])->filter()->all())
                                ->live()
                                // Nobody changes their own roles (the pivot also keeps a Super Admin's).
                                ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false)
                                // Super Admins have every permission, so other roles add nothing: keep it to one.
                                ->afterStateHydrated(fn (CheckboxList $component, ?array $state) => $component->state(static::withoutRedundantRoles($state)))
                                ->afterStateUpdated(fn (CheckboxList $component, ?array $state) => $component->state(static::withoutRedundantRoles($state)))
                                ->disableOptionWhen(function (string $value, ?User $record, Get $get): bool {
                                    $superAdmin = static::superAdminRoleId();
                                    $isSuperAdmin = in_array($superAdmin, array_map('strval', (array) $get('roles')), true);

                                    // Other roles mean nothing next to Super Admin.
                                    if ($isSuperAdmin && $value !== $superAdmin) {
                                        return true;
                                    }

                                    // Only roles you could hand out yourself (nobody grants more than they have).
                                    return ! auth()->user()->canGrantRole(Role::query()->with('permissions')->find($value) ?? new Role);
                                })
                                // A role the editor can't grant stays as it is: kept if the person has it,
                                // refused if it's new. Filament's own check would reject kept, disabled boxes.
                                ->in(fn (CheckboxList $component, ?User $record): array => array_map('strval', array_merge(
                                    array_keys(array_filter($component->getOptions(), fn (mixed $label, string|int $id): bool => auth()->user()->canGrantRole(Role::query()->with('permissions')->find($id) ?? new Role), ARRAY_FILTER_USE_BOTH)),
                                    $record?->roles->modelKeys() ?? [],
                                )))
                                ->helperText(fn (?User $record, Get $get): ?string => match (true) {
                                    $record?->is(auth()->user()) => __('You can’t change your own roles. Ask someone else who manages people.'),
                                    in_array(static::superAdminRoleId(), array_map('strval', (array) $get('roles')), true) => __('Super Admins have every permission, so other roles do not apply.'),
                                    default => null,
                                })
                                ->required(),
                            // Multi-site: the sites this person works on (none: the default site).
                            CheckboxList::make('sites')
                                ->label(__('Sites'))
                                ->relationship('sites', 'name')
                                ->columns(2)
                                // With one site there is nothing to choose.
                                ->visible(fn (): bool => SiteSettings::canManageSites() && Site::query()->count() > 1)
                                ->helperText(__('None ticked: the default site. People who manage sites, and Super Admins, work on every site.')),
                        ]),
                    Section::make(__('Activity'))
                        ->icon('phosphor-chart-bar')
                        ->visibleOn(Operation::Edit)
                        ->compact()
                        ->schema([
                            TextEntry::make('pages_count')->label(__('Pages written'))->state(fn (User $record): int => $record->pages()->count())->badge(),
                            TextEntry::make('ai_count')->label(__('AI actions'))->state(fn (User $record): int => $record->mcpActivities()->count())->badge()->color('violet'),
                            TextEntry::make('created_at')->label(__('Member since'))->date(),
                        ]),
                ]),
            ]);
    }

    public static function superAdminRoleId(): ?string
    {
        return once(fn (): ?string => Role::query()->where('name', User::SUPER_ADMIN)->value('id'));
    }

    /**
     * @param  array<int, string>|null  $roles
     * @return array<int, string>
     */
    public static function withoutRedundantRoles(?array $roles): array
    {
        $roles = array_map('strval', $roles ?? []);
        $superAdmin = static::superAdminRoleId();

        return in_array($superAdmin, $roles, true) ? [$superAdmin] : array_values($roles);
    }
}
