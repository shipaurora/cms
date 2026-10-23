<?php

namespace Aurora\Filament\Resources\Pages\Concerns;

use Aurora\Enums\RevisionSource;
use Aurora\Filament\Forms\Components\WriteWithAi;
use Aurora\Models\Page;
use Aurora\Support\AiTranslator;
use Aurora\Support\BlockCatalogue;
use Aurora\Support\BlockTranslations;
use Aurora\Support\ContentLocale;
use Aurora\Support\RevisionContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Translating a page in the visual editor (?language=nb). The page has one block
 * structure for every language: in another language the canvas shows that language's
 * text (or the page's own language's, where there is none yet), only text can be
 * changed, and saving keeps just the text, as the page's block translations.
 *
 * Adding, moving, removing, resizing and restyling blocks happens in the page's own
 * language (Page::primaryLocale()), for every language at once.
 */
trait TranslatesBlocks
{
    /**
     * Text kept the same as the page's own language's on purpose ("<block id>.<field>"):
     * translated, though it doesn't differ.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $sameText = [];

    /**
     * @var array<int, string>
     */
    #[Locked]
    public array $savedSameText = [];

    #[Locked]
    #[Url]
    public ?string $language = null;

    public function bootedTranslatesBlocks(): void
    {
        $this->forgetUnavailableLanguage();

        ContentLocale::set($this->editingLocale());
    }

    /**
     * Only another language this page is in can be translated into.
     */
    protected function forgetUnavailableLanguage(): void
    {
        $page = $this->record ?? null;

        if (! $page instanceof Page) {
            return;
        }

        if (! $page->isAvailableIn((string) $this->language) || $this->language === $page->primaryLocale()) {
            $this->language = null;
        }
    }

    public function isTranslating(): bool
    {
        return $this->language !== null;
    }

    /**
     * The language on the canvas: the one being translated into, or the page's own.
     */
    public function editingLocale(): string
    {
        $page = $this->record ?? null;

        return $this->language ?? ($page instanceof Page ? $page->primaryLocale() : ContentLocale::default());
    }

    /**
     * Load the language's text onto the shared structure.
     */
    protected function loadTranslation(): void
    {
        $this->forgetUnavailableLanguage();
        ContentLocale::set($this->editingLocale());

        if (! $this->isTranslating()) {
            return;
        }

        /** @var Page $page */
        $page = $this->getRecord();

        $this->blocks = $this->prepareBlocks($page->localizedBlocks($this->language));
        $this->sameText = BlockTranslations::sameAsSource($this->prepareBlocks($page->blocks ?? []), $page->block_translations[$this->language] ?? []);
        $this->history()->clear();
        $this->markSaved();
    }

    /**
     * Keep a block's text as the page's own language's in the one being translated into: a
     * name or a title that's the same in both, counted as translated from now on.
     */
    public function keepBlockText(string $id): void
    {
        $index = $this->indexOf($id);

        if (! $this->isTranslating() || $index === null) {
            return;
        }

        $source = collect($this->prepareBlocks($this->getRecord()->blocks ?? []))->firstWhere('id', $id);

        foreach (BlockTranslations::fieldsOf((string) $this->blocks[$index]['type']) as $field) {
            $value = $this->blocks[$index]['data'][$field] ?? null;

            if (filled($value) && $value === ($source['data'][$field] ?? null) && ! in_array("{$id}.{$field}", $this->sameText, true)) {
                $this->sameText[] = "{$id}.{$field}";
            }
        }

        $this->syncDirty();
    }

    /**
     * Unsaved: blocks changed, or text kept the same since the last save.
     */
    protected function syncDirty(): void
    {
        parent::syncDirty();

        $this->isDirty = $this->isDirty || $this->sameText !== $this->savedSameText;
    }

    protected function markSaved(): void
    {
        parent::markSaved();

        $this->savedSameText = $this->sameText;
    }

    protected function saveTranslation(): void
    {
        $this->commitSelectedBlock();

        /** @var Page $page */
        $page = $this->getRecord();
        $page->refresh();

        if ($this->loadedUpdatedAt !== null && optional($page->updated_at)->toJSON() !== $this->loadedUpdatedAt) {
            Notification::make()->title(__('page-builder::chrome.saved_elsewhere'))->danger()->send();

            return;
        }

        RevisionContext::as(RevisionSource::Canvas, 'Translated into '.ContentLocale::all()[$this->language], function () use ($page): void {
            $page->block_translations = [
                ...($page->block_translations ?? []),
                $this->language => BlockTranslations::extract($this->prepareBlocks($page->blocks ?? []), $this->blocks, $this->sameText),
            ];
            $page->save();
        });

        $this->loadedUpdatedAt = optional($page->fresh()->updated_at)->toJSON();
        $this->markSaved();

        Notification::make()->title(__('Translation saved'))->success()->send();
    }

    /**
     * "Translate with AI": the page's text into the language being edited, in one go. The
     * canvas text goes in as unsaved changes to review (and undo) before saving; the page's
     * title, summary and SEO text, if chosen, are saved straight away.
     */
    public function translateWithAiAction(): Action
    {
        return Action::make('translateWithAi')
            ->label(__('Translate with AI'))
            ->icon('phosphor-sparkle')
            ->color('gray')
            ->size('sm')
            ->tooltip(__('Translate with AI'))
            ->visible(fn (): bool => $this->isTranslating() && WriteWithAi::isAvailable())
            ->modalHeading(fn (): string => __('Translate this page into :language?', ['language' => ContentLocale::all()[$this->language] ?? $this->language]))
            ->modalDescription(__('The assistant translates the text on the canvas. Look it over, then save the translation.'))
            ->modalIcon('phosphor-sparkle')
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('Translate'))
            ->schema([
                Radio::make('scope')
                    ->hiddenLabel()
                    ->options([
                        'missing' => __('Only text that isn’t translated yet'),
                        'all' => __('All text (replaces existing translations)'),
                    ])
                    ->default('missing'),
                Checkbox::make('page_fields')
                    ->label(__('Also the page’s title, summary and SEO text, where they have no translation yet'))
                    ->default(true),
            ])
            ->action(function (array $data, Action $action): void {
                $this->commitSelectedBlock();

                $texts = $this->textsToTranslate(($data['scope'] ?? 'missing') === 'all');
                $pageTexts = ($data['page_fields'] ?? false) ? $this->pageTextsToTranslate() : [];

                if ($texts === [] && $pageTexts === []) {
                    Notification::make()->title(__('Everything is translated already'))->success()->send();

                    return;
                }

                try {
                    $translated = AiTranslator::translate([...$texts, ...$pageTexts], $this->language, $this->getRecord()->primaryLocale());
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->title(__('The assistant couldn’t translate the page'))->body(Str::limit($exception->getMessage(), 200))->danger()->send();
                    $action->halt();
                }

                $this->applyTranslatedTexts(array_intersect_key($translated, $texts));
                $this->savePageTranslations(array_intersect_key($translated, $pageTexts));

                Notification::make()
                    ->title(trans_choice(':count text translated|:count texts translated', count($translated)))
                    ->body(__('Look it over, then save the translation.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The canvas text to translate, by "block id|field path" (lists are translated item by item).
     *
     * @return array<string, string>
     */
    protected function textsToTranslate(bool $everything): array
    {
        /** @var Page $page */
        $page = $this->getRecord();
        $structure = collect($this->prepareBlocks($page->blocks ?? []))->keyBy('id');
        $texts = [];

        foreach ($this->blocks as $block) {
            $source = $structure->get($block['id']);

            foreach (BlockTranslations::fieldsOf($block['type']) as $field) {
                $original = $source['data'][$field] ?? null;
                $current = $block['data'][$field] ?? null;

                if (blank($original) || (! $everything && $current !== $original)) {
                    continue;
                }

                foreach (Arr::dot([$field => $original]) as $path => $text) {
                    if (is_string($text) && preg_match('/\pL/u', $text) && ! Str::isUuid($text) && ! Str::startsWith($text, ['http', '/', '#', 'hero-'])) {
                        $texts[$block['id'].'|'.$path] = $text;
                    }
                }
            }
        }

        return $texts;
    }

    /**
     * @param  array<string, string>  $translated
     */
    protected function applyTranslatedTexts(array $translated): void
    {
        if ($translated === []) {
            return;
        }

        $this->remember();

        foreach ($translated as $key => $text) {
            [$id, $path] = explode('|', $key, 2);
            $index = $this->indexOf($id);

            if ($index !== null) {
                data_set($this->blocks[$index]['data'], $path, $text);
            }
        }

        $this->syncDirty();

        // The inspector shows the selected block's fields: refresh them too.
        if ($this->selectedId !== null && ($index = $this->indexOf($this->selectedId)) !== null) {
            $this->blockData = $this->blocks[$index]['data'] ?? [];
            $this->cacheSchema('form', null);
            $this->form->fill($this->blockData);
        }
    }

    /**
     * The page's own text with no translation in the language yet, by "page|attribute".
     *
     * @return array<string, string>
     */
    protected function pageTextsToTranslate(): array
    {
        /** @var Page $page */
        $page = $this->getRecord();

        return collect(['title', 'excerpt', 'meta_title', 'meta_description'])
            ->filter(fn (string $attribute): bool => blank($page->getTranslation($attribute, $this->language, false)) && filled($page->getTranslation($attribute, $page->primaryLocale(), false)))
            ->mapWithKeys(fn (string $attribute): array => ['page|'.$attribute => $page->getTranslation($attribute, $page->primaryLocale(), false)])
            ->all();
    }

    /**
     * @param  array<string, string>  $translated
     */
    protected function savePageTranslations(array $translated): void
    {
        if ($translated === []) {
            return;
        }

        /** @var Page $page */
        $page = $this->getRecord();

        foreach ($translated as $key => $text) {
            $page->setTranslation(Str::after($key, 'page|'), $this->language, $text);
        }

        RevisionContext::as(RevisionSource::Canvas, 'Translated into '.ContentLocale::all()[$this->language], fn () => $page->save());

        // Saved here, not by "Save translation": that save must not mistake it for someone else's.
        $this->loadedUpdatedAt = optional($page->fresh()->updated_at)->toJSON();
    }

    /**
     * Blocks with text that is still the page's own language's.
     *
     * @return array<int, string>
     */
    public function untranslatedBlockIds(): array
    {
        if (! $this->isTranslating()) {
            return [];
        }

        /** @var Page $page */
        $page = $this->getRecord();
        $structure = collect($this->prepareBlocks($page->blocks ?? []))->keyBy('id');

        return collect($this->blocks)
            ->filter(function (array $block) use ($structure): bool {
                $source = $structure->get($block['id']);

                return $source !== null && collect(BlockTranslations::fieldsOf($block['type']))->contains(
                    fn (string $field): bool => filled($source['data'][$field] ?? null) && ($block['data'][$field] ?? null) === $source['data'][$field]
                        && ! in_array($block['id'].'.'.$field, $this->sameText, true),
                );
            })
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Fields that aren't text are shared by every language: shown, but locked.
     *
     * @param  array<int, mixed>  $components
     */
    protected function lockSharedFields(array $components, string $type): void
    {
        $shared = collect(BlockCatalogue::describe($type)['fields'] ?? [])->pluck('name')->diff(BlockTranslations::fieldsOf($type))->all();

        $lock = function (array $components) use (&$lock, $shared): void {
            foreach ($components as $component) {
                // Disabled fields drop out of the form's state; keep them, or committing the
                // inspector would wipe the block's layout, image and links.
                if ($component instanceof Field && in_array($component->getName(), $shared, true)) {
                    $component->disabled()->dehydrated()->hint(__('Same in every language'));
                }

                if ($component instanceof Component) {
                    $lock($component->getDefaultChildComponents());
                }
            }
        };

        $lock($components);
    }

    /**
     * Whatever the inspector sends back, a translation only changes text: the block keeps
     * its shared fields as they are.
     */
    public function commitSelectedBlock(): void
    {
        $index = $this->selectedId === null ? null : $this->indexOf($this->selectedId);
        $before = $index === null ? null : $this->blocks[$index];

        parent::commitSelectedBlock();

        if (! $this->isTranslating() || $before === null || ! isset($this->blocks[$index])) {
            return;
        }

        $text = array_intersect_key($this->blocks[$index]['data'] ?? [], array_flip(BlockTranslations::fieldsOf($before['type'])));
        $this->blocks[$index]['data'] = array_replace($before['data'] ?? [], $text);
        $this->syncDirty();
    }

    protected function refuseWhileTranslating(): bool
    {
        if (! $this->isTranslating()) {
            return false;
        }

        Notification::make()
            ->title(__('The layout is the same in every language'))
            ->body(__('Switch to :language to add, move, remove or restyle blocks.', ['language' => ContentLocale::all()[$this->getRecord()->primaryLocale()] ?? '']))
            ->warning()
            ->send();

        return true;
    }

    public function moveBlock(string $id, int $to, ?string $parent = null, ?string $slot = null): void
    {
        $this->refuseWhileTranslating() || parent::moveBlock($id, $to, $parent, $slot);
    }

    public function insertBlock(string $type, ?int $at = null, ?string $parent = null, ?string $slot = null): ?string
    {
        return $this->refuseWhileTranslating() ? null : parent::insertBlock($type, $at, $parent, $slot);
    }

    public function duplicateBlock(string $id): void
    {
        $this->refuseWhileTranslating() || parent::duplicateBlock($id);
    }

    public function removeBlock(string $id): void
    {
        $this->refuseWhileTranslating() || parent::removeBlock($id);
    }

    public function revealGhost(string $id): void
    {
        $this->refuseWhileTranslating() || parent::revealGhost($id);
    }

    public function pasteBlocks(string $payload, ?string $targetId = null, string $placement = 'after'): ?string
    {
        return $this->refuseWhileTranslating() ? null : parent::pasteBlocks($payload, $targetId, $placement);
    }

    public function resizeBlock(string $id, string $axis, mixed $value = null): void
    {
        $this->refuseWhileTranslating() || parent::resizeBlock($id, $axis, $value);
    }

    public function resizeColumns(string $id, array $percentages): void
    {
        $this->refuseWhileTranslating() || parent::resizeColumns($id, $percentages);
    }

    public function resetBlockStyle(string $id): void
    {
        $this->refuseWhileTranslating() || parent::resetBlockStyle($id);
    }

    public function pasteBlockStyle(string $id, array $payload): void
    {
        $this->refuseWhileTranslating() || parent::pasteBlockStyle($id, $payload);
    }

    // The Style and Layout tabs are hidden while translating; nothing they hold is kept.
    public function commitSelectedSettings(): void
    {
        $this->isTranslating() || parent::commitSelectedSettings();
    }

    public function commitSelectedStyle(): void
    {
        $this->isTranslating() || parent::commitSelectedStyle();
    }

    public function commitSelectedAnchor(): void
    {
        $this->isTranslating() || parent::commitSelectedAnchor();
    }
}
