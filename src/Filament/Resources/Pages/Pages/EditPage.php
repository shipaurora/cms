<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Enums\PageStatus;
use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\FollowsActiveLocale;
use Aurora\Filament\Concerns\RefreshesFromAssistant;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Resources\Pages\Actions\DuplicatePageAction;
use Aurora\Filament\Resources\Pages\Actions\OpenVisualEditorAction;
use Aurora\Filament\Resources\Pages\Actions\SearchIndexAction;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Filament\Resources\PageTemplates\PageTemplateResource;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\CurrentSite;
use Aurora\Support\EditLocks;
use Aurora\Support\PageRedirects;
use Aurora\Support\PageTemplates;
use Aurora\Support\WorkingCopies;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

/**
 * @property Page $record
 */
class EditPage extends EditRecord
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use FollowsActiveLocale;
    use RefreshesFromAssistant;
    use SavesFromHeader;
    use Translatable {
        Translatable::updatedActiveLocale as updatedTranslatableActiveLocale;
        Translatable::fillForm as fillTranslatableForm;
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
        return match (true) {
            $this->record->hasWorkingCopy() => __('Live, with changes that aren’t on the website yet.'),
            $this->record->isScheduled() => __('Scheduled for :date', ['date' => $this->record->published_at->isoFormat('LLL')]),
            $this->record->hasEnded() => __('Came down :when, at its unpublish date.', ['when' => $this->record->unpublish_at->diffForHumans()]),
            default => $this->record->status->getDescription(),
        };
    }

    /**
     * Changes that aren't on the website yet say so first, in a banner with what to do next.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(__('Changes not on the website yet'))
                ->description(fn (): string => __('Saved :when by :name. Visitors still see the page as it was until the changes are published.', [
                    'when' => $this->record->working_copy_at?->diffForHumans(),
                    'name' => $this->record->workingCopyBy?->name ?? __('someone'),
                ]))
                ->icon('phosphor-pencil-simple-line')
                ->warning()
                ->actions([
                    $this->publishChangesAction(),
                    Action::make('previewChanges')
                        ->label(__('Preview'))
                        ->icon('phosphor-eye')
                        ->color('gray')
                        ->url(fn (): string => $this->record->previewUrl(), shouldOpenInNewTab: true),
                    $this->discardChangesAction()->color('gray'),
                ])
                ->visible(fn (): bool => $this->record->hasWorkingCopy()),
            $this->getFormContentComponent(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function publishChangesAction(): Action
    {
        return Action::make('publishChanges')
            ->label(__('Publish changes'))
            ->icon('phosphor-rocket-launch')
            ->color('success')
            ->visible(fn (): bool => $this->record->hasWorkingCopy() && auth()->user()->can('publish', $this->record))
            ->requiresConfirmation()
            ->modalIcon('phosphor-rocket-launch')
            ->modalHeading(__('Publish the changes?'))
            ->modalDescription(__('The website shows the page as it is here, straight away.'))
            ->modalSubmitActionLabel(__('Publish changes'))
            ->action(function (): void {
                $this->record->publishWorkingCopy();
                $this->fillForm();
                $this->rememberData();
                $this->dispatch('page-saved');
                Notification::make()->title(__('Changes published'))->body($this->record->url())->success()->send();
            });
    }

    public function discardChangesAction(): Action
    {
        return Action::make('discardChanges')
            ->label(__('Discard'))
            ->icon('phosphor-arrow-counter-clockwise')
            ->color('danger')
            ->visible(fn (): bool => $this->record->hasWorkingCopy() && auth()->user()->can('update', $this->record))
            ->requiresConfirmation()
            ->modalHeading(__('Discard the changes?'))
            ->modalDescription(__('The page goes back to how it is on the website. The changes can’t be brought back.'))
            ->modalSubmitActionLabel(__('Discard changes'))
            ->action(function (): void {
                $this->record->discardWorkingCopy();
                $this->record->refresh();
                $this->fillForm();
                $this->rememberData();
                Notification::make()->title(__('Changes discarded'))->success()->send();
            });
    }

    /**
     * The page as its working copy has it, when it has one: that's what's being edited.
     */
    protected function fillForm(): void
    {
        $this->record->applyWorkingCopy();

        $this->fillTranslatableForm();
    }

    /**
     * The form only has the chosen layout's fields: the page keeps what it has for other
     * layouts' views (back to a layout, its background image is still there).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('settings', $data)) {
            $settings = $this->record->settings();

            foreach ((array) ($data['settings']['layouts'] ?? []) as $view => $fields) {
                $settings['layouts'][$view] = $fields;
            }

            $data['settings'] = $settings ?: null;
        }

        return $data;
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

        // A live page's new content goes into its working copy, not onto the website.
        WorkingCopies::capture(fn () => parent::save($shouldRedirect, $shouldSendSavedNotification));
    }

    /**
     * Saved into the working copy: say so, so nobody thinks it's on the website.
     */
    protected function getSavedNotificationTitle(): ?string
    {
        return $this->record->hasWorkingCopy() ? __('Saved. Not on the website until you publish the changes.') : parent::getSavedNotificationTitle();
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
                filled($data['title'] ?? null) => Page::uniqueSlug((string) $data['title'], $locale, $this->record->getKey(), $this->record->parent_id),
                default => null,
            };

            if ($slug !== null) {
                $slugs[$locale] = $slug;
            }
        }

        return PageRedirects::changes($this->record, $slugs);
    }

    /**
     * Someone else is editing the page: say so, instead of saving over their work.
     */
    protected function beforeSave(): void
    {
        if (EditLocks::heldByOther($this->record)) {
            Notification::make()
                ->title(__(':name is editing this page', ['name' => EditLocks::holder($this->record)['name']]))
                ->body(__('Your changes weren’t saved. Take over editing to save them.'))
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        PageRedirects::keep($this->record, $this->redirectsToKeep);

        // The page kept its live content; what's edited (and shown on switching language) is
        // the working copy.
        $this->record->applyWorkingCopy();

        // The history below the form lists the version this save made.
        $this->dispatch('page-saved');

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
            OpenVisualEditorAction::make(),
            Action::make('preview')
                ->label(__('Preview'))
                ->icon('phosphor-eye')
                ->color('gray')
                // The live page, unless it has changes not on the website yet: those, as they'll look.
                ->url(fn (): string => $this->record->isLive() && ! $this->record->hasWorkingCopy() ? $this->record->url() : $this->record->previewUrl(), shouldOpenInNewTab: true),
            $this->publishChangesAction(),
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
                $this->discardChangesAction()->label(__('Discard unpublished changes')),
                DuplicatePageAction::make(),
                // A page template made from this page as it is now, for new pages to start from.
                Action::make('saveAsPageTemplate')
                    ->label(__('Save as page template'))
                    ->icon('phosphor-files')
                    ->visible(fn (): bool => Page::storesTemplates() && auth()->user()->can('manage-templates Page'))
                    ->modalHeading(__('Save as page template'))
                    ->modalDescription(__('New pages can start from it: this page’s layout and blocks, ready to fill in. Changing either later doesn’t change the other.'))
                    ->modalIcon('phosphor-files')
                    ->modalWidth('lg')
                    ->fillForm(fn (): array => ['name' => $this->record->title])
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(80),
                        TextInput::make('description')->label(__('Description'))->helperText(__('Shown with the template when starting a new page.'))->maxLength(160),
                    ])
                    ->modalSubmitActionLabel(__('Save as template'))
                    ->action(function (array $data): void {
                        $template = PageTemplates::fromPage($this->record, $data['name'], $data['description'] ?? null);
                        Notification::make()
                            ->title(__('Saved “:name” as a page template', ['name' => $template->title]))
                            ->actions([Action::make('design')->label(__('Design it'))->url(PageTemplateResource::getUrl('design', ['record' => $template]))])
                            ->success()
                            ->send();
                    }),
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
