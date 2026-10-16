<?php

namespace Aurora\Filament\Resources\Users;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Pages\UsersAndRoles;
use Aurora\Filament\Resources\Users\Pages\CreateUser;
use Aurora\Filament\Resources\Users\Pages\EditUser;
use Aurora\Filament\Resources\Users\Pages\ListUsers;
use Aurora\Filament\Resources\Users\Schemas\UserForm;
use Aurora\Filament\Resources\Users\Tables\UsersTable;
use Aurora\Models\User;
use Aurora\Support\SearchThumbnail;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = User::class;

    /**
     * Shared by every site: not tied to the site picked in the admin.
     */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-users';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-users-bold';

    // In "Users & Roles", with a side menu between the two.
    protected static ?string $cluster = UsersAndRoles::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var User $record */
        return SearchThumbnail::title($record->name, filament()->getUserAvatarUrl($record), round: true);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var User $record */
        return array_filter(['Email' => $record->email, 'Role' => $record->roleLabels()->implode(', ') ?: null]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('roles');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
