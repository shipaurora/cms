<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Support\CurrentSite;
use Aurora\Enums\PageStatus;
use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Pages\Actions\DuplicatePageAction;
use Aurora\Filament\Resources\Pages\Actions\SearchIndexAction;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\PageRedirects;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

/**
 * @property Page $record
 */
class EditPage extends EditRecord
{
    use BreadcrumbsToList;
    use FollowsActiveLocale;
    use SavesFromHeader;
    use Translatable {
        Translatable::updatedActiveLocale as updatedTranslatableActiveLocale;
        FollowsActiveLocale::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = PageResource::class;

    /**
     * Whether the question about the old addresses was answered for this save.
     */
    protected bool $addressesDecided = false;

    /**
     * Old addresses to redirect once the page is saved.
     *
     * @var array<int, string>
     */
    protected array $redirectsToKeep = [];

    public function getTitle(): string|Htmlable
    {
        return $this->record->title;
    }

    public function getSubheading(): ?string
    {
        return $this->record->isScheduled()
            ? __('Scheduled for :date', ['date' => $this->record->published_at->isoFormat('LLL')])
            : $this->record->status->getDescription();
    }

    /**
     * A live page whose address changes in any of its languages asks first whether the old
     * addresses should keep working.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if (! $this->addressesDecided && $this->addressChanges() !== []) {
            $this->mountAction('keepOldAddresses');

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    public function keepOldAddressesAction(): Action
    {
        return Action::make('keepOldAddresses')
            ->modalIcon('phosphor-arrow-bend-up-right')
            ->modalHeading(fn (): string => trans_choice('Keep the old address working?|Keep the old addresses working?', count($this->addressChanges())))
            ->modalDescription(__('This page is live and its address changes. Links and search results still point to the old address; a redirect sends visitors on to the new one.'))
            ->modalWidth('lg')
            ->schema(fn (): array => [
                CheckboxList::make('redirect')
                    ->hiddenLabel()
                    ->options(collect($this->addressChanges())->map(fn (array $change): string => $change['from'].' → '.$change['to'])->all())
                    ->descriptions(count($this->pageLocales()) > 1
                        ? collect($this->addressChanges())->map(fn (array $change, string $locale): string => ContentLocale::all()[$locale] ?? $locale)->all()
                        : []),
            ])
            ->fillForm(fn (): array => ['redirect' => array_keys($this->addressChanges())])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (array $data): void {
                $chosen = array_intersect_key($this->addressChanges(), array_flip($data['redirect'] ?? []));

                $this->redirectsToKeep = array_column($chosen, 'from');
                $this->addressesDecided = true;
                $this->save();
            });
    }

    /**
     * The page's addresses that this save changes, in every language being saved (an empty
     * address is made from the title again).
     *
     * @return array<string, array{from: string, to: string}>
     */
    protected function addressChanges(): array
    {
        $slugs = [];

        foreach ([$this->activeLocale => $this->data, ...$this->otherLocaleData] as $locale => $data) {
            if (! in_array($locale, $this->pageLocales(), true)) {
                continue;
            }

            $slug = match (true) {
                filled($data['slug'] ?? null) => Str::slug((string) $data['slug']),
                filled($data['title'] ?? null) => Page::uniqueSlug((string) $data['title'], $locale, $this->record->getKey()),
                default => null,
            };

            if ($slug !== null) {
                $slugs[$locale] = $slug;
            }
        }

        return PageRedirects::changes($this->record, $slugs);
    }

    protected function afterSave(): void
    {
        PageRedirects::keep($this->record, $this->redirectsToKeep);

        if ($this->record->wasChanged('is_homepage') && $this->record->is_homepage) {
            Notification::make()
                ->title(__('“:title” is now the homepage', ['title' => $this->record->title]))
                ->body(__('Visitors see it at :url. Its old address redirects there.', ['url' => CurrentSite::url('/')]))
                ->success()
                ->send();
        }
    }

    /**
     * Only the languages this page is in.
     *
     * @return array<int, string>
     */
    public function getTranslatableLocales(): array
    {
        return $this->pageLocales();
    }

    /**
     * The page's languages as ticked in the form right now (saved or not), so the language
     * switcher follows the ticks.
     *
     * @return array<int, string>
     */
    protected function pageLocales(): array
    {
        $ticked = $this->data['locales'] ?? null;

        if (! is_array($ticked) || $ticked === []) {
            return $this->record->availableLocales();
        }

        return array_values(array_intersect(array_keys(ContentLocale::all()), $ticked)) ?: $this->record->availableLocales();
    }

    /**
     * The page's own language: the default site language if ticked, else the first ticked.
     */
    protected function pagePrimaryLocale(): string
    {
        $locales = $this->pageLocales();

        return in_array(ContentLocale::default(), $locales, true) ? ContentLocale::default() : $locales[0];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Open in a language the page is in: its own, unless another was picked.
        if (! in_array($this->activeLocale, $this->pageLocales(), true)) {
            $this->setActiveLocale($this->pagePrimaryLocale());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count($this->pageLocales()) > 1),
            Action::make('design')
                ->label(__('Open visual editor'))
                ->icon('phosphor-paint-brush')
                ->color('gray')
                ->url(fn (): string => PageResource::getUrl('design', ['record' => $this->record])),
            Action::make('preview')
                ->label(__('Preview'))
                ->icon('phosphor-eye')
                ->color('gray')
                ->url(fn (): string => $this->record->isLive() ? $this->record->url() : $this->record->previewUrl(), shouldOpenInNewTab: true),
            Action::make('publish')
                ->label(__('Publish'))
                ->icon('phosphor-rocket-launch')
                ->color('success')
                ->visible(fn (): bool => $this->record->status !== PageStatus::Published && auth()->user()->can('publish', $this->record))
                ->requiresConfirmation()
                ->modalDescription(__('The page goes live on the website immediately.'))
                ->action(function (): void {
                    $this->record->update(['status' => PageStatus::Published, 'published_at' => $this->record->published_at ?? now()]);
                    $this->refreshFormData(['status', 'published_at']);
                    Notification::make()->title(__('Published'))->body($this->record->url())->success()->send();
                }),
            Action::make('submitForReview')
                ->label(__('Submit for review'))
                ->icon('phosphor-paper-plane-right')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === PageStatus::Draft && ! auth()->user()->can('publish', $this->record))
                ->action(function (): void {
                    $this->record->update(['status' => PageStatus::Review]);
                    $this->refreshFormData(['status']);
                    Notification::make()->title(__('Sent for review'))->success()->send();
                }),
            ActionGroup::make([
                DuplicatePageAction::make(),
                // Search: what it knows about the page, and a box to try searches with.
                SearchIndexAction::make(),
                // Search: the page's text worked out anew and sent to the index.
                Action::make('reindex')
                    ->label(__('Reindex'))
                    ->icon('phosphor-arrows-clockwise')
                    ->visible(fn (): bool => auth()->user()->can('manage-search Page'))
                    ->action(function (): void {
                        $this->record->reindex();
                        Notification::make()->title(__('Reindexed for search'))->success()->send();
                    }),
                Action::make('unpublish')
                    ->label(__('Unpublish'))
                    ->icon('phosphor-eye-slash')
                    ->color('warning')
                    ->visible(fn (): bool => $this->record->status === PageStatus::Published && auth()->user()->can('publish', $this->record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (): ?string => $this->record->is_homepage ? PageResource::withoutHomepage($this->record) : null)
                    ->action(function (): void {
                        $this->record->update(['status' => PageStatus::Draft]);
                        $this->refreshFormData(['status']);
                    }),
                DeleteAction::make()
                    ->modalDescription(fn (): ?string => $this->record->is_homepage ? PageResource::withoutHomepage($this->record) : null),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])->icon('phosphor-dots-three-vertical')->color('gray')->button()->label(__('More')),
            $this->headerSaveButton(),
        ];
    }
}
