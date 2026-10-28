<?php

namespace Aurora\Filament\Widgets;

use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\PageRevision;
use Aurora\Support\UserBadge;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\HtmlString;

class RecentChanges extends TableWidget
{
    /**
     * Its name and what it shows, when customising the dashboard (DashboardLayout).
     */
    public static function getDashboardLabel(): string
    {
        return __('Recent changes');
    }

    public static function getDashboardDescription(): string
    {
        return __('Every save, from every surface, with who made it.');
    }

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected function getTableHeading(): string
    {
        return __('Recent changes');
    }

    protected static ?string $pollingInterval = '10s';

    public function table(Table $table): Table
    {
        return $table
            ->query(PageRevision::query()->with(['page', 'user'])->whereHas('page')->latest('created_at'))
            ->description(__('Every save, from every surface: page settings, the visual editor and AI assistants.'))
            ->paginated([5])
            ->defaultPaginationPageOption(8)
            ->columns([
                // Three narrow columns that wrap, so the card never scrolls sideways.
                TextColumn::make('source')->badge()->grow(false),
                TextColumn::make('page.title')
                    ->label(__('Page'))
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (PageRevision $record): ?string => $record->summaryText()),
                TextColumn::make('user.name')
                    ->label(__('By'))
                    ->html()
                    ->formatStateUsing(fn (PageRevision $record): ?HtmlString => UserBadge::html($record->user))
                    ->placeholder(__('System'))
                    ->wrap()
                    ->description(fn (PageRevision $record): string => collect([
                        $record->created_at->diffForHumans(),
                        $record->source->isAi() ? __('via :source', ['source' => lcfirst($record->source->getLabel())]) : null,
                    ])->filter()->implode(' · '))
                    ->tooltip(fn (PageRevision $record): string => $record->created_at->isoFormat('LLL')),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('phosphor-paint-brush')
                    ->url(fn (PageRevision $record): string => PageResource::getUrl('design', ['record' => $record->page_id]))
                    ->visible(fn (PageRevision $record): bool => PageResource::canEdit($record->page)),
            ]);
    }
}
