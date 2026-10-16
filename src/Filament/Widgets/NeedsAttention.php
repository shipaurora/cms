<?php

namespace Aurora\Filament\Widgets;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class NeedsAttention extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 1];

    protected function getTableHeading(): string
    {
        return __('Needs attention');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Page::query()->with('author')->where(fn (Builder $query) => $query
                ->where('status', PageStatus::Review)
                ->orWhere(fn (Builder $query) => $query->where('status', PageStatus::Published)->where('published_at', '>', now()))))
            ->paginated(false)
            ->columns([
                Stack::make([
                    TextColumn::make('title')
                        ->weight('semibold')
                        ->wrap()
                        ->description(fn (Page $record): string => $record->isScheduled()
                            ? __('Goes live :when', ['when' => $record->published_at->diffForHumans()])
                            : __('From :name, :when', ['name' => $record->author?->name ?? __('someone'), 'when' => $record->updated_at->diffForHumans()])),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (Page $record): string => $record->isScheduled() ? __('Scheduled') : $record->status->getLabel())
                        ->color(fn (Page $record): string => $record->isScheduled() ? 'info' : 'warning'),
                ])->space(2),
            ])
            ->recordUrl(fn (Page $record): string => PageResource::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Action::make('approve')
                    ->label(__('Publish'))
                    ->icon('phosphor-check')
                    ->color('success')
                    ->button()
                    ->size('xs')
                    ->visible(fn (Page $record): bool => $record->status === PageStatus::Review && auth()->user()->can('publish', $record))
                    ->requiresConfirmation()
                    ->action(function (Page $record): void {
                        $record->update(['status' => PageStatus::Published, 'published_at' => now()]);
                        Notification::make()->title(__('“:title” is live', ['title' => $record->title]))->success()->send();
                    }),
            ])
            ->emptyStateIcon('phosphor-check-circle')
            ->emptyStateHeading(__('All caught up'))
            ->emptyStateDescription(__('Nothing waiting for review.'));
    }
}
