<?php

namespace Aurora\Filament;

use Aurora\Enums\PageStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin for a website's own model published like a page (Aurora\Models\Concerns\
 * HasPublishing): the form's Publishing section, the table's status column and its filter,
 * the same as the pages'.
 *
 *     $schema->components([..., Publishing::section()]);
 *     $table->columns([..., Publishing::column()])->filters([Publishing::filter()]);
 */
class Publishing
{
    public static function section(): Section
    {
        $published = fn (Get $get): bool => in_array($get('status'), [PageStatus::Published, PageStatus::Published->value], true);

        return Section::make(__('Publishing'))
            ->icon('phosphor-rocket-launch')
            ->schema([
                ToggleButtons::make('status')
                    ->hiddenLabel()
                    ->options(PageStatus::class)
                    ->default(PageStatus::Draft)
                    ->required()
                    ->live()
                    ->grouped()
                    ->extraAttributes(['class' => 'cms-toggle-buttons-full']),
                DateTimePicker::make('published_at')
                    ->label(__('Publish date'))
                    ->native(false)
                    ->seconds(false)
                    ->prefixIcon('phosphor-calendar-dots')
                    ->helperText(fn (Get $get): string => $get('published_at') && now()->lt($get('published_at'))
                        ? __('Scheduled: it goes live automatically at this time.')
                        : __('Leave empty to publish immediately.'))
                    ->live()
                    ->visible($published),
                DateTimePicker::make('unpublish_at')
                    ->label(__('Unpublish date'))
                    ->native(false)
                    ->seconds(false)
                    ->prefixIcon('phosphor-calendar-x')
                    ->after('published_at')
                    ->helperText(fn (Get $get): string => filled($get('unpublish_at'))
                        ? __('It comes down by itself at this time.')
                        : __('Leave empty to keep it live.'))
                    ->live()
                    ->visible($published),
            ]);
    }

    /**
     * The status as the pages list shows it: scheduled and ended told apart from published.
     */
    public static function column(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('Status'))
            ->badge()
            ->formatStateUsing(fn (Model $record): string => match (true) {
                $record->isScheduled() => __('Scheduled'),
                $record->hasEnded() => __('Ended'),
                default => $record->status->getLabel(),
            })
            ->color(fn (Model $record): string => match (true) {
                $record->isScheduled() => 'info',
                $record->hasEnded() => 'gray',
                default => $record->status->getColor(),
            })
            ->icon(fn (Model $record): string => match (true) {
                $record->isScheduled() => 'phosphor-clock',
                $record->hasEnded() => 'phosphor-calendar-x',
                default => $record->status->getIcon(),
            })
            ->tooltip(fn (Model $record): ?string => match (true) {
                $record->isScheduled() => __('Goes live :when', ['when' => $record->published_at->diffForHumans()]),
                $record->hasEnded() => __('Came down :when', ['when' => $record->unpublish_at->diffForHumans()]),
                $record->isLive() && $record->unpublish_at !== null => __('Comes down :when', ['when' => $record->unpublish_at->diffForHumans()]),
                default => null,
            })
            ->sortable();
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label(__('Status'))
            ->options(PageStatus::class)
            ->multiple();
    }
}
