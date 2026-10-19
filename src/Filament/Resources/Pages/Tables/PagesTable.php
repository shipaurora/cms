<?php

namespace Aurora\Filament\Resources\Pages\Tables;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Resources\Pages\Actions\DuplicatePageAction;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\CurrentSite;
use Aurora\Support\EditLocks;
use Aurora\Support\PeoplePicker;
use Aurora\Support\UserBadge;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Page $record): ?string => PageResource::canEdit($record) ? PageResource::getUrl('edit', ['record' => $record]) : null)
            // Who last edited each row, loaded with the rows.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['lastEditor', 'workingCopyBy']))
            ->columns([
                TextColumn::make('title')
                    ->weight(FontWeight::SemiBold)
                    // On the site's domain, as the address field shows it: filament-cms-demo.test/about.
                    ->description(fn (Page $record): string => Str::after(CurrentSite::url($record->path()), '://'))
                    ->icon(fn (Page $record): ?string => $record->is_homepage ? 'phosphor-house-bold' : null)
                    ->iconColor('primary')
                    // A key after the title of a password-protected page.
                    ->html()
                    ->formatStateUsing(fn (Page $record): HtmlString => new HtmlString(e($record->title)
                        .($record->isPasswordProtected() ? static::titleIcon('phosphor-key', __('Password protected')) : '')
                        .(($editor = EditLocks::holder($record)) !== null ? static::titleIcon('phosphor-lock-simple', __(':name is editing this page', ['name' => $editor['name']])) : '')))
                    ->wrap()
                    ->searchable(['title', 'slug'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByTitle($direction)),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (Page $record): string => match (true) {
                        $record->hasWorkingCopy() => __('Changes waiting'),
                        $record->isScheduled() => __('Scheduled'),
                        $record->hasEnded() => __('Ended'),
                        default => $record->status->getLabel(),
                    })
                    ->color(fn (Page $record): string => match (true) {
                        $record->hasWorkingCopy() => 'warning',
                        $record->isScheduled() => 'info',
                        $record->hasEnded() => 'gray',
                        default => $record->status->getColor(),
                    })
                    ->icon(fn (Page $record): string => match (true) {
                        $record->hasWorkingCopy() => 'phosphor-pencil-simple-line',
                        $record->isScheduled() => 'phosphor-clock',
                        $record->hasEnded() => 'phosphor-calendar-x',
                        default => $record->status->getIcon(),
                    })
                    ->tooltip(fn (Page $record): ?string => match (true) {
                        $record->hasWorkingCopy() => __('Live. Changes saved :when by :name aren’t on the website yet.', ['when' => $record->working_copy_at?->diffForHumans(), 'name' => $record->workingCopyBy?->name ?? __('someone')]),
                        $record->isScheduled() => __('Goes live :when', ['when' => $record->published_at->diffForHumans()]),
                        $record->hasEnded() => __('Came down :when', ['when' => $record->unpublish_at->diffForHumans()]),
                        $record->isLive() && $record->unpublish_at !== null => __('Comes down :when', ['when' => $record->unpublish_at->diffForHumans()]),
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('layout')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('blocks')
                    ->label(__('Blocks'))
                    ->state(fn (Page $record): int => $record->blockCount())
                    ->icon('phosphor-squares-four')
                    ->color('gray')
                    ->alignCenter()
                    // Secondary details give way on narrower screens, so the row actions stay in view.
                    ->visibleFrom('2xl'),
                ImageColumn::make('author.avatar')
                    ->label(__('Author'))
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(fn (Page $record): string => 'https://ui-avatars.com/api/?background=random&name='.urlencode($record->author?->name ?? '?'))
                    ->tooltip(fn (Page $record): ?string => $record->author?->name)
                    ->visibleFrom('2xl')
                    ->toggleable(),
                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->isoDate('ll')
                    ->placeholder(__('Not yet'))
                    ->sortable()
                    ->visibleFrom('xl')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label(__('Last edited'))
                    ->since()
                    ->description(fn (Page $record): ?HtmlString => UserBadge::html($record->lastEditor))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PageStatus::class)
                    ->multiple(),
                SelectFilter::make('layout')
                    ->relationship('layout', 'name')
                    ->preload(),
                SelectFilter::make('author')
                    ->relationship('author', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $user): string => PeoplePicker::option($user))
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field->allowHtml())
                    ->indicateUsing(fn (array $state): ?string => filled($state['value'] ?? null) ? __('Author').': '.User::query()->whereKey($state['value'])->value('name') : null)
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('design')
                    ->label(__('Design'))
                    ->icon('phosphor-paint-brush')
                    ->url(fn (Page $record): string => PageResource::getUrl('design', ['record' => $record]))
                    ->visible(fn (Page $record): bool => PageResource::canEdit($record) && ! $record->trashed()),
                ActionGroup::make([
                    // Reviewed and ready: publish without opening the page.
                    Action::make('publishPage')
                        ->label(__('Publish'))
                        ->icon('phosphor-rocket-launch')
                        ->color('success')
                        ->visible(fn (Page $record): bool => $record->status === PageStatus::Review && ! $record->trashed())
                        ->authorize(fn (Page $record): bool => (bool) auth()->user()?->can('publish', $record))
                        ->requiresConfirmation()
                        ->modalIcon('phosphor-rocket-launch')
                        ->modalHeading(fn (Page $record): string => __('Publish “:title”?', ['title' => $record->title]))
                        ->modalDescription(fn (Page $record): string => $record->published_at?->isFuture()
                            ? __('It goes live on :date, as scheduled.', ['date' => $record->published_at->isoFormat('LLL')])
                            : __('It goes live straight away.'))
                        ->modalSubmitActionLabel(__('Publish'))
                        ->action(function (Page $record): void {
                            $record->update(['status' => PageStatus::Published]);

                            Notification::make()->title(__('“:title” is published', ['title' => $record->title]))->success()->send();
                        }),
                    EditAction::make()->label(__('Settings'))->icon('phosphor-sliders-horizontal'),
                    Action::make('view')
                        ->label(fn (Page $record): string => $record->isLive() ? __('View on site') : __('Preview'))
                        ->icon('phosphor-arrow-square-out')
                        ->url(fn (Page $record): string => $record->isLive() ? $record->url() : $record->previewUrl(), shouldOpenInNewTab: true),
                    // Like "New page": the title first, then the page, already under this one.
                    Action::make('createSubpage')
                        ->label(__('Create subpage'))
                        ->icon('phosphor-tree-structure')
                        ->authorize(fn (): bool => PageResource::canCreate())
                        ->hidden(fn (Page $record): bool => $record->is_homepage || $record->trashed())
                        ->modalHeading(fn (Page $record): string => __('New page under “:page”', ['page' => $record->title]))
                        ->modalIcon('phosphor-file-plus')
                        ->modalWidth('md')
                        ->modalSubmitActionLabel(__('Continue'))
                        ->schema([
                            TextInput::make('title')
                                ->label(__('Title'))
                                ->placeholder(__('e.g. Getting started'))
                                ->required()
                                ->maxLength(255)
                                ->autofocus(),
                        ])
                        ->action(fn (array $data, Page $record, $livewire) => $livewire->redirect(PageResource::getUrl('create', ['title' => $data['title'], 'parent' => $record->getKey()]), navigate: true)),
                    DuplicatePageAction::make(),
                    DeleteAction::make()->modalDescription(fn (Page $record): ?string => $record->is_homepage ? PageResource::withoutHomepage($record) : null),
                    RestoreAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label(__('Publish'))
                        ->icon('phosphor-rocket-launch')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()->can('publish', Page::class))
                        // Page by page: an editor may publish only the pages they may edit.
                        ->authorizeIndividualRecords('publish')
                        ->action(function (Collection $records): void {
                            $records->each(fn (Page $page) => $page->update(['status' => PageStatus::Published]));
                            Notification::make()->title(trans_choice(':count page published|:count pages published', $records->count()))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('unpublish')
                        ->label(__('Move to drafts'))
                        ->icon('phosphor-eye-slash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()->can('publish', Page::class))
                        ->authorizeIndividualRecords('publish')
                        ->modalDescription(fn (BulkAction $action): ?string => static::homepageAmong($action))
                        ->action(fn (Collection $records) => $records->each(fn (Page $page) => $page->update(['status' => PageStatus::Draft])))
                        ->deselectRecordsAfterCompletion(),
                    // Search: their text worked out anew and sent to the index.
                    BulkAction::make('reindex')
                        ->label(__('Reindex'))
                        ->icon('phosphor-arrows-clockwise')
                        ->visible(fn (): bool => auth()->user()->can('manage-search Page'))
                        ->action(function (Collection $records): void {
                            $records->each->reindex();
                            Notification::make()->title(trans_choice(':count page reindexed for search|:count pages reindexed for search', $records->count()))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Page by page, as the policy allows; warned when the homepage is among them.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->modalDescription(fn (BulkAction $action): ?string => static::homepageAmong($action)),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('phosphor-file-plus')
            ->emptyStateHeading(__('No pages yet'))
            ->emptyStateDescription(__('Create your first page and design it visually.'));
    }

    /**
     * When the homepage is among the selected pages: what visitors then see.
     */
    protected static function homepageAmong(BulkAction $action): ?string
    {
        $homepage = $action->getSelectedRecords()->first(fn (Page $page): bool => $page->is_homepage);

        return $homepage ? PageResource::withoutHomepage($homepage) : null;
    }

    /**
     * A small icon after a page's title (a password, unpublished changes), named for screen readers.
     */
    protected static function titleIcon(string $icon, string $label): string
    {
        return ' <span class="cms-page-title-key" title="'.e($label).'">'.svg($icon, 'size-4')->toHtml().'<span class="sr-only">'.e($label).'</span></span>';
    }
}
