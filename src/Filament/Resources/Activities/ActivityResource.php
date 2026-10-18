<?php

namespace Aurora\Filament\Resources\Activities;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\Activities\Pages\ListActivities;
use Aurora\Models\Activity;
use Aurora\Models\User;
use Aurora\Support\ActivityLog;
use Aurora\Support\PeoplePicker;
use Aurora\Support\UserBadge;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Administration › Activity log: who changed what across the CMS (Aurora\Support\ActivityLog),
 * newest first. Read only.
 */
class ActivityResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-clock-counter-clockwise';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?int $navigationSort = 3;

    /**
     * The words for what happened.
     *
     * @return array<string, string>
     */
    public static function actions(): array
    {
        return [
            'created' => __('Created'),
            'updated' => __('Changed'),
            'deleted' => __('Deleted'),
            'restored' => __('Restored'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'site']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->since()
                    ->dateTimeTooltip()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Who'))
                    ->html()
                    ->formatStateUsing(fn (Activity $record): ?HtmlString => UserBadge::html($record->user))
                    ->placeholder(__('Someone since removed')),
                TextColumn::make('action')
                    ->label(__('What'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::actions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'restored' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('subject_label')
                    ->label(__('Item'))
                    ->weight('medium')
                    ->state(fn (Activity $record): string => $record->subject_label ?? '—')
                    ->description(fn (Activity $record): string => collect([
                        ActivityLog::typeName($record->subject_type),
                        $record->site?->name,
                        filled($record->changes) ? __('Changed: :fields', ['fields' => collect($record->changes)->map(fn (string $field): string => str_replace('_', ' ', $field))->implode(', ')]) : null,
                    ])->filter()->implode(' · '))
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('user')
                    ->label(__('Who'))
                    ->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $user): string => PeoplePicker::option($user))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->allowHtml())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('subject_type')
                    ->label(__('Kind'))
                    ->options(collect(ActivityLog::MODELS)->mapWithKeys(fn (string $name, string $model): array => [(new $model)->getMorphClass() => __($name)])->all()),
                SelectFilter::make('action')
                    ->label(__('What'))
                    ->options(fn (): array => static::actions()),
                Filter::make('when')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('from')->label(__('From')),
                            DatePicker::make('until')->label(__('Until')),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->emptyStateIcon('phosphor-clock-counter-clockwise')
            ->emptyStateHeading(__('Nothing changed yet'))
            ->emptyStateDescription(__('Changes people make to pages, menus, files, users and settings are listed here.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
