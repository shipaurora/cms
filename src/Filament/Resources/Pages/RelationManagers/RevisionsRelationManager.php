<?php

namespace Aurora\Filament\Resources\Pages\RelationManagers;

use Aurora\Enums\RevisionSource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Models\PageRevision;
use Aurora\Support\ContentLocale;
use Aurora\Support\RevisionContext;
use Aurora\Support\UserBadge;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'History';

    protected static string|\BackedEnum|null $icon = 'phosphor-clock';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __(static::$title);
    }

    protected static function getModelLabel(): ?string
    {
        return __('version');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('versions');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->revisions()->count();
    }

    /**
     * Saving the page (EditPage) adds a version: the list shows it straight away.
     */
    #[On('page-saved')]
    public function refreshHistory(): void
    {
        $this->resetPage();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('summary')
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('summary')
                    ->formatStateUsing(fn (PageRevision $record): ?string => $record->summaryText())
                    ->description(fn (PageRevision $record): string => trans_choice(':count block · “:title”|:count blocks · “:title”', $record->blockCount(), ['title' => Arr::get((array) ($record->snapshot['title'] ?? []), ContentLocale::current(), Arr::first((array) ($record->snapshot['title'] ?? [])) ?? '')]))
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label(__('By'))
                    ->placeholder(__('System'))
                    ->html()
                    ->formatStateUsing(fn (PageRevision $record): ?HtmlString => UserBadge::html($record->user))
                    ->description(fn (PageRevision $record): ?string => $record->source->isAi() ? __('via :source', ['source' => lcfirst($record->source->getLabel())]) : null),
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->filters([
                SelectFilter::make('source')->options(RevisionSource::class),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('Preview'))
                    ->icon('phosphor-eye')
                    ->color('gray')
                    ->url(fn (PageRevision $record): string => URL::temporarySignedRoute('pages.preview', now()->addHour(), ['previewPage' => $record->page_id, 'revision' => $record->getKey()]), shouldOpenInNewTab: true),
                Action::make('restore')
                    ->label(__('Restore'))
                    ->icon('phosphor-arrow-u-up-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Restore this version?'))
                    ->modalDescription(__('The page goes back to exactly how it was. Nothing is lost: the current version stays in the history.'))
                    ->visible(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))
                    ->action(function (PageRevision $record): void {
                        /** @var Page $page */
                        $page = $this->getOwnerRecord();

                        RevisionContext::as(
                            RevisionSource::Restore,
                            // In English, translated when shown (PageRevision::summaryText()).
                            'Restored the version from '.$record->created_at->format('Y-m-d H:i'),
                            fn () => $page->update(Page::attributesFromSnapshot($record->snapshot)),
                        );

                        Notification::make()->title(__('Version restored'))->success()->send();
                        $this->redirect(PageResource::getUrl('edit', ['record' => $page]));
                    }),
            ])
            ->paginated([10, 25, 50]);
    }
}
