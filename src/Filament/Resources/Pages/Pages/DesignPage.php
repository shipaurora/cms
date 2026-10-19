<?php

namespace Aurora\Filament\Resources\Pages\Pages;

use Aurora\Enums\RevisionSource;
use Aurora\Filament\Concerns\EditsOnCanvas;
use Aurora\Filament\Resources\Pages\Concerns\TranslatesBlocks;
use Aurora\Filament\Resources\Pages\PageResource;
use Aurora\Support\BlockTranslations;
use Aurora\Support\EditLocks;
use Aurora\Support\Layouts;
use Aurora\Support\RevisionContext;
use Aurora\Support\WorkingCopies;
use CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage as BaseDesignPage;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The visual editor for a page.
 *
 * Adds to the canvas (EditsOnCanvas): saves are recorded as "Visual editor" revisions, a
 * live page's changes wait in its working copy, and pages can be translated (TranslatesBlocks).
 */
class DesignPage extends BaseDesignPage
{
    use EditsOnCanvas {
        selectedBlockSchema as liveBlockSchema;
        setBlockField as setCanvasBlockField;
    }
    use TranslatesBlocks;

    protected static string $resource = PageResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->useWorkingCopy();
        $this->loadTranslation();
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

    public function exitUrl(): ?string
    {
        return PageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * Live fields (EditsOnCanvas), and while translating, the fields every language shares
     * are locked.
     *
     * @return array<int, mixed>
     */
    protected function selectedBlockSchema(): array
    {
        $components = $this->liveBlockSchema();

        if ($this->isTranslating() && $components !== []) {
            $this->lockSharedFields($components, (string) $this->selectedBlockType());
        }

        return $components;
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

        return view(Layouts::viewFor($page), ['page' => $page, 'layout' => $page->layoutOrDefault(), 'preview' => false, 'embedded' => true])->render();
    }

    public function setBlockField(string $id, string $field, mixed $value): void
    {
        // Translating: only text can change (lists' items are text too).
        $index = $this->indexOf($id);
        $root = Str::before($field, '.');

        if ($this->isTranslating() && ($index === null || ! in_array($root, BlockTranslations::fieldsOf($this->blocks[$index]['type']), true))) {
            return;
        }

        $this->setCanvasBlockField($id, $field, $value);
    }
}
