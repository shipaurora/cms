<?php

namespace Aurora\Filament\Resources\Users\Tables;

use Aurora\Models\Role;
use Aurora\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use STS\FilamentImpersonate\Actions\Impersonate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?background=7c3aed&color=fff&name='.urlencode($record->name)),
                TextColumn::make('name')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (User $record): ?string => $record->job_title)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')->copyable()->searchable()->color('gray'),
                TextColumn::make('roles.name')
                    ->label(__('Roles'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Role::findByName($state, 'web')->displayName())
                    ->color(fn (string $state): string => match ($state) {
                        User::SUPER_ADMIN => 'danger',
                        'Editor' => 'primary',
                        'Author' => 'info',
                        default => 'gray',
                    }),
                ToggleColumn::make('is_active')
                    ->label(__('Active'))
                    ->disabled(fn (User $record): bool => $record->is(auth()->user()) || ! auth()->user()->can('update', $record)),
            ])
            ->filters([
                SelectFilter::make('roles')->relationship('roles', 'name')->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->displayName())->multiple()->preload(),
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    Impersonate::make()
                        ->icon('phosphor-detective-bold')
                        ->label(__('View as'))
                        ->tooltip(fn (User $record): string => __('See the CMS exactly as :name does', ['name' => $record->name]))
                        ->redirectTo(fn (): string => filament()->getUrl()),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
