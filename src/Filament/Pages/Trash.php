<?php

namespace Aurora\Filament\Pages;

use Aurora\Filament\Concerns\TranslatesPageLabels;
use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Aurora\Filament\Resources\Assets\AssetResource;
use Aurora\Filament\Resources\Forms\FormResource;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Asset;
use Aurora\Models\Form;
use Aurora\Models\Page as CmsPage;
use Aurora\Support\AssetAccess;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Everything deleted from the content screens, in one place: pages, media and forms stay
 * here until someone restores them or deletes them for good. One kind at a time, in tabs.
 */
class Trash extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;
    use WithoutBreadcrumbs;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-trash';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-trash-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Trash';

    protected string $view = 'aurora::filament.pages.trash';

    /**
     * The kind of content shown: a key of kinds().
     */
    #[Url]
    public string $kind = 'pages';

    /**
     * What can be in the trash, with the resource each belongs to, its title column and what
     * narrows it (media: the files this person may read).
     *
     * @return array<string, array{model: class-string<Model>, resource: class-string, label: string, icon: string, title: string, scope?: Closure(Builder): mixed}>
     */
    public static function kinds(): array
    {
        return array_filter([
            'pages' => ['model' => CmsPage::class, 'resource' => PageResource::class, 'label' => __('Pages'), 'icon' => 'phosphor-file-text', 'title' => 'title'],
            'media' => ['model' => Asset::class, 'resource' => AssetResource::class, 'label' => __('Media'), 'icon' => 'phosphor-images', 'title' => 'title', 'scope' => fn (Builder $query) => AssetAccess::scopeReadable($query, auth()->user())],
            'forms' => ['model' => Form::class, 'resource' => FormResource::class, 'label' => __('Forms'), 'icon' => 'phosphor-clipboard-text', 'title' => 'name'],
        ], fn (array $kind): bool => (bool) auth()->user()?->can('restoreAny', $kind['model']));
    }

    /**
     * One kind's trashed items.
     *
     * @param  array{model: class-string<Model>, scope?: Closure(Builder): mixed}  $kind
     */
    protected static function trashed(array $kind): Builder
    {
        $query = $kind['model']::onlyTrashed();

        // Not when(): it would call the scope as its condition.
        if (isset($kind['scope'])) {
            ($kind['scope'])($query);
        }

        return $query;
    }

    public static function canAccess(): bool
    {
        return static::kinds() !== [];
    }

    /**
     * In the sidebar only while something is in the trash; the page itself stays reachable.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && static::getNavigationBadge() !== null;
    }

    public static function getNavigationBadge(): ?string
    {
        // Asked twice per page (whether to show, and the badge): counted once.
        $count = once(fn (): int => collect(static::kinds())->sum(fn (array $kind): int => static::trashed($kind)->count()));

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Items in the trash');
    }

    public function getSubheading(): ?string
    {
        return __('Deleted pages, media and forms. Restore them, or delete them permanently.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('emptyTrash')
                ->label(__('Empty trash'))
                ->icon('phosphor-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->emptiable() !== [])
                ->requiresConfirmation()
                ->modalHeading(__('Empty the trash?'))
                ->modalDescription(fn (): string => __('Everything in the trash is deleted for good: :items. This cannot be undone.', [
                    'items' => collect($this->emptiable())->map(fn (int $count, string $kind): string => trans_choice(match ($kind) {
                        'pages' => ':count page|:count pages',
                        'forms' => ':count form|:count forms',
                        default => ':count file|:count files',
                    }, $count))->join(', '),
                ]))
                ->modalSubmitActionLabel(__('Delete everything'))
                ->action(function (): void {
                    $deleted = 0;

                    // One by one, so each item's clean-up runs (an image's files, say).
                    foreach (array_keys($this->emptiable()) as $kind) {
                        static::trashed(static::kinds()[$kind])->each(function (Model $record) use (&$deleted): void {
                            $record->forceDelete();
                            $deleted++;
                        });
                    }

                    $this->resetTable();
                    $this->dispatch('refresh-sidebar');

                    Notification::make()->title(trans_choice(':count item deleted for good|:count items deleted for good', $deleted))->success()->send();
                }),
        ];
    }

    /**
     * What emptying the trash would delete: the count of each kind the person may delete for good.
     *
     * @return array<string, int>
     */
    protected function emptiable(): array
    {
        return array_filter(
            array_map(fn (array $kind): int => auth()->user()->can('forceDeleteAny', $kind['model']) ? static::trashed($kind)->count() : 0, static::kinds()),
        );
    }

    public function mount(): void
    {
        // Filament checks access after mount: nothing this person may restore, no page.
        abort_unless(static::canAccess(), 403);

        if (! array_key_exists($this->kind, static::kinds())) {
            $this->kind = array_key_first(static::kinds());
        }

        $this->showKindWithItems();
    }

    public function updatedKind(): void
    {
        $this->resetTable();
    }

    /**
     * After every change: emptied the kind on screen (restored or deleted its last item)?
     * Then on to one that still has something in it.
     */
    public function rendering(): void
    {
        if ($this->showKindWithItems()) {
            $this->resetTable();
        }
    }

    /**
     * Only kinds with something in the trash have a tab: show one of those.
     */
    protected function showKindWithItems(): bool
    {
        $withItems = array_keys(array_filter($this->counts()));

        if ($withItems === [] || in_array($this->kind, $withItems, true)) {
            return false;
        }

        $this->kind = $withItems[0];

        return true;
    }

    /**
     * How many of each kind are in the trash, for the tabs.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return array_map(fn (array $kind): int => static::trashed($kind)->count(), static::kinds());
    }

    /**
     * @return array{model: class-string<Model>, resource: class-string, label: string, icon: string, title: string}
     */
    protected function currentKind(): array
    {
        $kinds = static::kinds();

        return $kinds[$this->kind] ?? reset($kinds);
    }

    public function table(Table $table): Table
    {
        $kind = $this->currentKind();

        return $table
            ->query(fn (): Builder => static::trashed($kind)->when($kind['model'] === Form::class, fn (Builder $query) => $query->withCount('submissions')))
            ->defaultSort('deleted_at', 'desc')
            ->recordTitleAttribute($kind['title'])
            ->columns(array_values(array_filter([
                $this->kind === 'media'
                    ? ViewColumn::make('thumbnail')->label('')->view('aurora::filament.assets.thumbnail')->viewData(['compact' => true])
                    : null,
                TextColumn::make($kind['title'])
                    ->label(__('Name'))
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Model $record): ?string => match (true) {
                        $record instanceof CmsPage => $record->path(),
                        $record instanceof Form => trans_choice(':count submission|:count submissions', $record->submissions_count),
                        default => null,
                    })
                    ->searchable(),
                TextColumn::make('deleted_at')
                    ->label(__('Deleted'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->color('gray'),
            ])))
            ->recordActions([
                // A plain page: Filament doesn't check the policies for these here by itself.
                RestoreAction::make()->authorize('restore'),
                ForceDeleteAction::make()->authorize('forceDelete')->label(__('Delete'))->modalDescription(fn (): string => match ($this->kind) {
                    'media' => __('The file is removed from the library for good, and any page still using it shows an empty spot.'),
                    'forms' => __('The form and every submission sent through it are removed for good.'),
                    default => __('The page and its history are removed for good. This cannot be undone.'),
                }),
                ActionGroup::make([
                    Action::make('preview')
                        ->label(__('Preview'))
                        ->icon('phosphor-eye')
                        ->visible(fn (Model $record): bool => $record instanceof CmsPage)
                        ->url(fn (CmsPage $record): string => $record->previewUrl(), shouldOpenInNewTab: true),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                    ForceDeleteBulkAction::make()->label(__('Delete'))->authorizeIndividualRecords('forceDelete'),
                ]),
            ])
            ->emptyStateIcon('phosphor-trash')
            ->emptyStateHeading(fn (): string => __('No :kind in the trash', ['kind' => mb_strtolower($kind['label'])]))
            ->emptyStateDescription(__('Deleted items show up here until they are restored or deleted for good.'));
    }
}
