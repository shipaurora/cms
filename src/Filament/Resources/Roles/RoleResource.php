<?php

namespace Aurora\Filament\Resources\Roles;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\Roles\Pages\CreateRole;
use Aurora\Filament\Resources\Roles\Pages\EditRole;
use Aurora\Filament\Resources\Roles\Pages\ListRoles;
use Aurora\Filament\Resources\Roles\RelationManagers\UsersRelationManager;
use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Support\Permissions;
use Aurora\Support\SearchThumbnail;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;
use UnitEnum;

/**
 * Roles, presented as what people can do rather than as permission strings.
 *
 * Replaces the roles & permissions plugin's own resource (see its config file). The
 * permissions themselves are defined in code (Aurora\Support\Permissions), so editors pick
 * from plain-language descriptions instead of maintaining raw permission names.
 */
class RoleResource extends Resource
{
    use Translatable;
    use TranslatesResourceLabels;

    protected static ?string $model = Role::class;

    /**
     * Shared by every site: not tied to the site picked in the admin.
     */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-shield-check';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-shield-check-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Role'))
                    ->icon('phosphor-shield-check')
                    ->columns(2)
                    ->schema([
                        // The name people see, per language. A new role's first name is also its
                        // fixed key (Role::$name), so that one must be free.
                        TextInput::make('label')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(60)
                            ->rules(fn (string $operation): array => $operation === 'create'
                                ? [Rule::unique(Role::class, 'name'), Rule::notIn([User::SUPER_ADMIN])]
                                : [])
                            ->validationAttribute(__('name'))
                            ->placeholder(__('e.g. Marketing')),
                        TextInput::make('description')
                            ->maxLength(255)
                            ->placeholder(__('What people with this role do')),
                    ]),
                ...collect(Permissions::catalogue())->map(fn (array $group, string $name): Section => Section::make(__($name))
                    ->description(__($group['description']))
                    ->icon($group['icon'])
                    ->collapsible()
                    ->compact()
                    ->schema([
                        CheckboxList::make('permissions_'.Str::slug($name, '_'))
                            ->label(__($name))
                            ->hiddenLabel()
                            ->options(collect($group['permissions'])->map(fn (array $permission): string => __($permission[0]))->all())
                            ->descriptions(collect($group['permissions'])->map(fn (array $permission): string => __($permission[1]))->all())
                            ->columns(['md' => 2, 'xl' => 3])
                            ->gridDirection('row')
                            ->bulkToggleable()
                            // Nobody grants a permission they don't have (saving keeps those as they were).
                            ->disableOptionWhen(fn (string $value): bool => ! auth()->user()->can($value))
                            // So a role's own, disabled boxes don't stop it from being saved.
                            ->in(fn (CheckboxList $component, ?Role $record): array => array_merge(
                                array_keys(array_filter($component->getOptions(), fn (mixed $label, string $permission): bool => auth()->user()->can($permission), ARRAY_FILTER_USE_BOTH)),
                                $record?->permissions->pluck('name')->all() ?? [],
                            )),
                    ]))->values()->all(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $total = count(Permissions::all());

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('users')->withCount(['users', 'permissions']))
            ->defaultSort('users_count', 'desc')
            ->recordUrl(fn (Role $record): ?string => static::canEdit($record) ? static::getUrl('edit', ['record' => $record]) : null)
            ->columns([
                TextColumn::make('label')
                    ->label(__('Name'))
                    ->state(fn (Role $record): string => $record->displayName())
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Role $record): ?string => $record->displayDescription())
                    ->icon(fn (Role $record): ?string => $record->name === User::SUPER_ADMIN ? 'phosphor-lock-bold' : null)
                    ->iconColor('danger')
                    ->searchable(['name', 'label']),
                ImageColumn::make('users.avatar')
                    ->label(__('People'))
                    ->disk('public')
                    ->circular()
                    ->stacked()
                    ->limit(5)
                    ->limitedRemainingText()
                    ->defaultImageUrl(fn (Role $record): string => 'https://ui-avatars.com/api/?background=e4e4e7&color=71717a&name=%20'),
                TextColumn::make('users_count')
                    ->label(__('Members'))
                    ->formatStateUsing(fn (int $state): string => trans_choice(':count person|:count people', $state))
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label(__('Can do'))
                    ->badge()
                    ->formatStateUsing(fn (Role $record, int $state): string => $record->name === User::SUPER_ADMIN ? __('Everything') : __(':count of :total', ['count' => $state, 'total' => $total]))
                    ->color(fn (Role $record, int $state): string => match (true) {
                        $record->name === User::SUPER_ADMIN => 'danger',
                        $state >= $total / 2 => 'primary',
                        $state > 0 => 'info',
                        default => 'gray',
                    })
                    ->tooltip(fn (Role $record): ?string => $record->name === User::SUPER_ADMIN
                        ? __('Built in. Super Admins have every permission, and the role cannot be changed or deleted.')
                        : $record->permissions->map(fn ($permission) => Permissions::label($permission->name))->implode(', ')),
                TextColumn::make('updated_at')
                    ->label(__('Changed'))
                    ->since()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Role $record): string => $record->users_count > 0
                        ? trans_choice(':count person will lose this role and its permissions.|:count people will lose this role and its permissions.', $record->users_count)
                        : __('Nobody has this role.')),
            ])
            ->emptyStateHeading(__('No roles yet'));
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * Form state for a role's permissions, split by group the way the form shows them.
     *
     * @return array<string, array<int, string>>
     */
    public static function permissionState(?Role $role): array
    {
        $granted = $role?->permissions->pluck('name')->all() ?? [];

        return collect(Permissions::grouped())
            ->mapWithKeys(fn (array $permissions, string $group): array => [
                'permissions_'.Str::slug($group, '_') => array_values(array_intersect($permissions, $granted)),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function permissionsFrom(array $data, ?Role $role = null): array
    {
        $requested = collect(Permissions::grouped())
            ->keys()
            ->flatMap(fn (string $group): array => (array) ($data['permissions_'.Str::slug($group, '_')] ?? []))
            ->intersect(Permissions::all());

        $user = auth()->user();

        if ($user === null || $user->isSuperAdmin()) {
            return $requested->values()->all();
        }

        // Only permissions the editor holds can be added or taken away; the role keeps the rest.
        $held = $user->getAllPermissions()->pluck('name');
        $existing = collect($role?->permissions->pluck('name') ?? []);

        return $requested->intersect($held)
            ->merge($existing->diff($held))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Roles are named in the admin panel's languages, not the website's.
     *
     * @return array<int, string>
     */
    public static function getTranslatableLocales(): array
    {
        return Role::adminLocales();
    }

    /**
     * The switcher starts in the language the admin panel is shown in.
     */
    public static function getDefaultTranslatableLocale(): string
    {
        return in_array(app()->getLocale(), static::getTranslatableLocales(), true) ? app()->getLocale() : static::getTranslatableLocales()[0];
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Role ? $record->displayName() : parent::getRecordTitle($record);
    }

    /**
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'label'];
    }

    /**
     * In global search, with the same icon as in the sidebar.
     */
    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return SearchThumbnail::title(strip_tags((string) static::getRecordTitle($record)), null, icon: static::getNavigationIcon());
    }
}
