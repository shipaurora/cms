<?php

namespace Aurora\Filament\Resources\Roles\RelationManagers;

use Aurora\Models\Role;
use Aurora\Models\User;
use Aurora\Support\PeoplePicker;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'People with this role';

    protected static string|\BackedEnum|null $icon = 'phosphor-users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(static::$title);
    }

    protected static function getModelLabel(): ?string
    {
        return __('user');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('users');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->users()->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?background=7c3aed&color=fff&name='.urlencode($record->name)),
                TextColumn::make('name')->weight(FontWeight::SemiBold)->description(fn (User $record): ?string => $record->job_title)->searchable(),
                TextColumn::make('email')->color('gray'),
            ])
            ->headerActions([
                Action::make('addPeople')
                    ->label(__('Add people'))
                    ->icon('phosphor-user-plus')
                    // Only someone who manages people and could hand out this role themselves.
                    ->visible(fn (): bool => $this->canChangeMembers())
                    ->modalHeading(fn (): string => __('Add people to :role', ['role' => $this->getOwnerRecord()->name]))
                    ->modalDescription(__('Pick everyone who should get this role. People keep the roles they already have.'))
                    ->modalIcon('phosphor-user-plus')
                    ->modalWidth('2xl')
                    ->modalSubmitActionLabel(__('Add selected'))
                    ->schema(PeoplePicker::schema(fn () => User::query()->whereKeyNot(auth()->id())->whereDoesntHave('roles', fn (Builder $query) => $query->whereKey($this->getOwnerRecord()->getKey()))))
                    ->action(function (array $data): void {
                        abort_unless($this->canChangeMembers(), 403);

                        /** @var Role $role */
                        $role = $this->getOwnerRecord();
                        // Never yourself: nobody changes their own roles.
                        $people = User::query()->whereKey(PeoplePicker::chosen($data))->whereKeyNot(auth()->id())->get();

                        $people->each(fn (User $user) => $user->assignRole($role));

                        if ($people->isNotEmpty()) {
                            Notification::make()
                                ->title(trans_choice(':count person now has the :role role|:count people now have the :role role', $people->count(), ['role' => $role->displayName()]))
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label(__('Remove'))
                    ->authorize(fn (User $record): bool => $this->canChangeMembers() && ! $record->is(auth()->user())),
            ])
            ->emptyStateHeading(__('Nobody has this role yet'));
    }

    /**
     * Adding people to this role, or taking them off it: for someone who manages people and
     * could hand the role out themselves.
     */
    protected function canChangeMembers(): bool
    {
        /** @var Role $role */
        $role = $this->getOwnerRecord();
        $user = auth()->user();

        return $user->can('update User') && $user->canGrantRole($role);
    }
}
