<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use Aurora\Enums\RevisionSource;
use Aurora\Filament\Resources\Pages\Concerns\TranslatesBlocks;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Support\BlockScreenshots;
use Aurora\Support\BlockTranslations;
use Aurora\Support\ContentLocale;
use Aurora\Support\EditLocks;
use Aurora\Support\InlineRichText;
use Aurora\Support\Layouts;
use Aurora\Support\RevisionContext;
use Aurora\Support\WorkingCopies;
use CarlJanzell\FilamentPageBuilder\Editable;
use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
use CarlJanzell\FilamentPageBuilder\Support\EmbedUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The visual editor for a page.
 *
 * Adds to the package's canvas: saves are recorded as "Visual editor" revisions, fields
 * inside repeater items (an FAQ question, a pricing feature) can be typed into directly on
 * the page, and pages can be translated (TranslatesBlocks).
 */
class DesignPage extends BaseDesignPage
{
    use TranslatesBlocks;

    protected static string $resource = PageResource::class;

    /**
     * The block list, with each block's picture (uploaded in Settings › Blocks, else its own).
     * A block of the website's with none is shown live instead, from its sample page
     * (Block::sample()), until it has a screenshot.
     *
     * @return array<int, array{type: string, label: string, icon: ?string, category: string, description: ?string, preview: ?string, sample: ?string}>
     */
    public function getPaletteProperty(): array
    {
        return array_map(function (array $item): array {
            $preview = Blocks::preview($item['type']);

            return [
                ...$item,
                'preview' => $preview,
                'sample' => $preview === null && Blocks::find($item['type']) !== null ? BlockScreenshots::sampleUrl($item['type'], minutes: null) : null,
            ];
        }, parent::getPaletteProperty());
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->useWorkingCopy();
        $this->loadTranslation();
    }

    /**
     * Blocks that need to know which page they're on (Blocks::page()) know it on the canvas
     * too, as on the website (PageController).
     */
    public function booted(): void
    {
        ViewFacade::share('currentPage', $this->getRecord());
    }

    /**
     * A live page with changes not on the website yet: the canvas shows (and edits) those.
     */
    protected function useWorkingCopy(): void
    {
        if ($this->getRecord()->hasWorkingCopy()) {
            $this->blocks = $this->prepareBlocks($this->getRecord()->applyWorkingCopy()->{$this->blocksAttribute()} ?? []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraBodyAttributes(): array
    {
        $attributes = parent::getExtraBodyAttributes();

        if ($this->isTranslating()) {
            $attributes['class'] = trim(($attributes['class'] ?? '').' cms-translating');
        }

        return $attributes;
    }

    /**
     * "Exit": back to the page being edited, not the page list.
     */
    public function exitLabel(): string
    {
        return __('Exit');
    }

    public function exitUrl(): ?string
    {
        return PageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * No "Form editor" button in the toolbar: content is edited on the canvas, and page
     * settings are one click away from the page list.
     */
    /**
     * The style presets (registered in AdminPanelProvider in English, before the editor's
     * language is known), labelled in the editor's language.
     *
     * @return array<string, array<int|string, string>>
     */
    public function styleTokens(): array
    {
        return array_map(fn (array $options): array => array_map(fn (string $label): string => __($label), $options), parent::styleTokens());
    }

    public function formEditorUrl(): ?string
    {
        return null;
    }

    /**
     * Every inspector field updates the canvas as soon as it changes.
     *
     * Block schemas are plain Filament fields, and a field that is not live only reaches
     * the server on the next request, so switching e.g. a form block from "In a card" to
     * "Plain" seemed to do nothing. Choices, toggles and selects become live; text fields
     * update when they lose focus, so typing does not redraw the canvas on each keystroke.
     * Fields that set their own live behaviour (a debounce, say) are left as they are.
     *
     * @return array<int, mixed>
     */
    protected function selectedBlockSchema(): array
    {
        $components = parent::selectedBlockSchema();

        static::makeLive($components);

        if ($this->isTranslating() && $components !== []) {
            $this->lockSharedFields($components, (string) $this->selectedBlockType());

            // Text that's the same in this language (a name, a title): translated as it is.
            array_unshift($components, Actions::make([
                Action::make('keepBlockText')
                    ->label(__('Same in :language', ['language' => ContentLocale::all()[$this->language] ?? $this->language]))
                    ->icon('phosphor-equals')
                    ->color('gray')
                    ->size('sm')
                    ->tooltip(__('The text stays as it is in this language, and counts as translated.'))
                    ->visible(fn (): bool => in_array($this->selectedId, $this->untranslatedBlockIds(), true))
                    ->action(fn () => $this->keepBlockText((string) $this->selectedId)),
            ])->key('keep-block-text'));
        }

        return $components;
    }

    /**
     * @param  array<int, mixed>  $components
     */
    protected static function makeLive(array $components): void
    {
        foreach ($components as $component) {
            if ($component instanceof Field && (fn (): mixed => $this->isLive)->call($component) === null) {
                $component instanceof TextInput || $component instanceof Textarea || $component instanceof RichEditor
                || $component instanceof TagsInput || $component instanceof ColorPicker
                    ? $component->live(onBlur: true)
                    : $component->live();
            }

            if ($component instanceof Component) {
                static::makeLive($component->getDefaultChildComponents());
            }
        }
    }

    public function save(): void
    {
        // Someone else is editing the page: say so, instead of saving over their work.
        if (EditLocks::heldByOther($this->getRecord())) {
            Notification::make()
                ->title(__(':name is editing this page', ['name' => EditLocks::holder($this->getRecord())['name']]))
                ->body(__('Your changes weren’t saved. Take over editing to save them.'))
                ->danger()
                ->send();

            return;
        }

        // A live page's new content goes into its working copy, not onto the website.
        WorkingCopies::capture(function (): void {
            if ($this->isTranslating()) {
                $this->saveTranslation();

                return;
            }

            RevisionContext::as(RevisionSource::Canvas, null, fn () => parent::save());
        });

        $this->getRecord()->applyWorkingCopy();
    }

    /**
     * Saved, but not on the website yet (the page's working copy): the toolbar says so, and
     * offers to publish.
     */
    public function hasUnpublishedChanges(): bool
    {
        return $this->getRecord()->hasWorkingCopy();
    }

    public function publishChangesAction(): Action
    {
        return Action::make('publishChanges')
            ->label(__('Publish'))
            ->icon('phosphor-rocket-launch')
            ->color('success')
            ->size('sm')
            ->visible(fn (): bool => ! $this->isDirty && $this->hasUnpublishedChanges() && auth()->user()->can('publish', $this->getRecord()))
            ->requiresConfirmation()
            ->modalIcon('phosphor-rocket-launch')
            ->modalHeading(__('Publish the changes?'))
            ->modalDescription(__('The website shows the page as it is here, straight away.'))
            ->modalSubmitActionLabel(__('Publish changes'))
            ->action(function (): void {
                $this->getRecord()->publishWorkingCopy();
                $this->getRecord()->refresh();

                Notification::make()->title(__('Changes published'))->body($this->getRecord()->url())->success()->send();
            });
    }

    /**
     * The assistant saved this page in the database. Draw that version on the canvas,
     * without reloading the admin page, so the chat stays open. Unsaved work on the
     * canvas is left as it is.
     */
    #[On('cms-copilot-refresh')]
    public function refreshFromAssistant(): void
    {
        if ($this->isDirty) {
            return;
        }

        $this->getRecord()->refresh()->applyWorkingCopy();

        $this->blocks = $this->prepareBlocks($this->getRecord()->{$this->blocksAttribute()} ?? []);
        $this->loadedUpdatedAt = optional($this->getRecord()->updated_at)->toJSON();
        $this->history()->clear();
        $this->loadTranslation();

        if (! $this->isTranslating()) {
            $this->markSaved();
        }

        $this->syncInspector();
    }

    /**
     * Leaving the editor with unsaved changes: resources/js/admin/editor-leave.js stops the
     * link and asks here, in Filament's dialog, whether to discard the changes or save them
     * first. Only addresses in this admin are followed.
     */
    public function leaveEditorAction(): Action
    {
        $leave = function (array $arguments): void {
            $url = (string) ($arguments['url'] ?? '');

            if (parse_url($url, PHP_URL_HOST) !== request()->getHost()) {
                $url = PageResource::getUrl('index');
            }

            $this->js('window.cmsLeaveEditor('.Js::from($url).')');
        };

        return Action::make('leaveEditor')
            ->requiresConfirmation()
            ->color('danger')
            ->modalIcon('phosphor-warning')
            ->modalHeading(__('Discard unsaved changes?'))
            ->modalDescription(__('You have changes on this page that are not saved yet. If you leave now, they are lost.'))
            ->modalSubmitActionLabel(__('Discard changes'))
            ->modalCancelActionLabel(__('Keep editing'))
            ->modalWidth('lg')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAndLeave', arguments: ['save' => true])
                    ->label(__('Save and leave'))
                    ->color('primary'),
            ])
            ->action(function (array $arguments) use ($leave): void {
                if ($arguments['save'] ?? false) {
                    $this->save();

                    // Not saved (e.g. someone else saved the page meanwhile): stay, the notice says why.
                    if ($this->isDirty) {
                        return;
                    }
                }

                $leave($arguments);
            });
    }

    /**
     * The Preview button shows the unsaved page inside the real site: header, menu, footer
     * and the site's own stylesheet, so what the editor previews is what visitors get.
     */
    public function previewDocument(): string
    {
        $this->commitSelectedBlock();
        $this->commitSelectedSettings();
        $this->commitSelectedStyle();
        $this->commitSelectedAnchor();

        // While translating, the canvas holds the language's text on the shared structure.
        $page = $this->getRecord()->replicate()->forceFill(['blocks' => $this->blocks, 'block_translations' => []]);
        $page->id = $this->getRecord()->getKey();

        return view(Layouts::viewFor($page), ['page' => $page, 'layout' => $page->layoutOrDefault(), 'settings' => $page->layoutSettings(), 'preview' => false, 'embedded' => true])->render();
    }

    /**
     * A link dropped onto an embed block on the canvas.
     *
     * Checked like the sidebar field: the link (or a pasted <iframe> snippet) must resolve
     * to an allowed embed host, otherwise the editor is told what to paste instead.
     */
    public function setEmbedUrl(string $id, string $url): void
    {
        $index = $this->indexOf($id);

        if ($index === null || $this->blocks[$index]['type'] !== 'embed' || ! $this->registry()->isVisible('embed')) {
            return;
        }

        $url = Str::limit(trim(EmbedUrl::fromSnippet($url)), 2048, '');

        if (($problem = EmbedUrl::problem($url)) !== null) {
            Notification::make()->title(__('That link cannot be embedded'))->body($problem)->warning()->send();

            return;
        }

        // Select first: selecting commits the inspector, which would otherwise write the
        // old address back over the dropped one.
        if ($this->selectedId !== $id) {
            $this->selectBlock($id);
        } else {
            $this->commitSelectedBlock();
        }

        if (($this->blocks[$index]['data']['url'] ?? null) === $url) {
            return;
        }

        $this->remember();
        $this->blocks[$index]['data']['url'] = $url;
        $this->syncDirty();

        $this->blockData['url'] = $url;
        $this->cacheSchema('form', null);
        $this->form->fill($this->blockData);

        Notification::make()->title(__('Embed updated'))->body($url)->success()->send();
    }

    public function setBlockField(string $id, string $field, mixed $value): void
    {
        // Translating: only text can change (lists' items are text too).
        $index = $this->indexOf($id);
        $root = Str::before($field, '.');

        if ($this->isTranslating() && ($index === null || ! in_array($root, BlockTranslations::fieldsOf($this->blocks[$index]['type']), true))) {
            return;
        }

        // Rich text written on the canvas: a document to check, not a string (InlineRichText).
        if (($target = $this->richTextTarget($id, $field)) !== null) {
            $this->setRichText($id, $target, $value);

            return;
        }

        if (! str_contains($field, '.')) {
            parent::setBlockField($id, $field, $value);

            return;
        }

        $this->setBlockItemField($id, $field, $value);
    }

    /**
     * What the canvas's rich text editor starts from: the field's stored text as a document,
     * or not editable there when it holds more than that editor knows (a table).
     *
     * @return array{editable: bool, document: array<string, mixed>|null}
     */
    public function richTextDocument(string $id, string $field): array
    {
        $target = $this->richTextTarget($id, $field);
        $document = $target === null ? null : InlineRichText::document(data_get($this->blocks[$target['index']]['data'] ?? [], $target['path']));

        return ['editable' => $document !== null, 'document' => $document];
    }

    /**
     * Where a rich text field written on the canvas goes, if the block declared it
     * Editable::richText() (itself, or in its repeater's items) and, while translating,
     * it's text the language has.
     *
     * @return array{index: int, path: string}|null
     */
    protected function richTextTarget(string $id, string $field): ?array
    {
        $index = $this->indexOf($id);
        $type = $index === null ? null : $this->blocks[$index]['type'];
        $block = $this->registry()->find($type);

        if ($block === null || ! $this->registry()->isVisible($type)) {
            return null;
        }

        if (preg_match('/^([a-z_]+)\.(\d{1,3})\.([a-z_]+)$/', $field, $path)) {
            $editable = is_subclass_of($block, Block::class) ? ($block::itemEditables()[$path[1]][$path[3]] ?? null) : null;
            $exists = is_array($this->blocks[$index]['data'][$path[1]][(int) $path[2]] ?? null);
        } else {
            $editable = PageBuilder::editablesFor($type)[$field] ?? null;
            $exists = true;
        }

        if (! $editable instanceof Editable || $editable->kind !== Editable::RICH_TEXT || ! $exists) {
            return null;
        }

        if ($this->isTranslating() && ! in_array(Str::before($field, '.'), BlockTranslations::fieldsOf($type), true)) {
            return null;
        }

        return ['index' => $index, 'path' => $field];
    }

    /**
     * @param  array{index: int, path: string}  $target
     */
    protected function setRichText(string $id, array $target, mixed $document): void
    {
        $current = data_get($this->blocks[$target['index']]['data'] ?? [], $target['path']);

        // Text the canvas can't edit is never written from it: its editor would have dropped part of it.
        if (! InlineRichText::isEditable($current)) {
            return;
        }

        $html = InlineRichText::html($document);

        if ($html === null || $html === ($current ?? '')) {
            return;
        }

        $this->remember();
        data_set($this->blocks[$target['index']]['data'], $target['path'], $html);
        $this->syncDirty();

        if ($this->selectedId === $id) {
            $this->blockData = $this->blocks[$target['index']]['data'];
            $this->cacheSchema('form', null);
            $this->form->fill($this->blockData);
        }
    }

    /**
     * Write `items.2.question` on a block, if the block declared that item field editable.
     *
     * Checked as strictly as the package checks its own fields: the canvas is a public
     * Livewire surface, so the path, the repeater item and the value type all have to be
     * what the block said they would be.
     */
    protected function setBlockItemField(string $id, string $field, mixed $value): void
    {
        if (! preg_match('/^([a-z_]+)\.(\d{1,3})\.([a-z_]+)$/', $field, $path) || ! is_string($value)) {
            return;
        }

        [, $repeater, $index, $itemField] = $path;

        $position = $this->indexOf($id);
        $type = $position === null ? null : $this->blocks[$position]['type'];
        $block = $this->registry()->find($type);

        if ($block === null || ! $this->registry()->isVisible($type) || ! is_subclass_of($block, Block::class)) {
            return;
        }

        $editable = $block::itemEditables()[$repeater][$itemField] ?? null;
        $items = $this->blocks[$position]['data'][$repeater] ?? null;

        if ($editable === null || ! $editable->accepts($value) || ! is_array($items) || ! is_array($items[(int) $index] ?? null)) {
            return;
        }

        if (($items[(int) $index][$itemField] ?? null) === $value) {
            return;
        }

        $this->remember();
        $this->blocks[$position]['data'][$repeater][(int) $index][$itemField] = $value;
        $this->syncDirty();

        if ($this->selectedId === $id) {
            $this->blockData = $this->blocks[$position]['data'];
            $this->cacheSchema('form', null);
            $this->form->fill($this->blockData);
        }
    }
}
