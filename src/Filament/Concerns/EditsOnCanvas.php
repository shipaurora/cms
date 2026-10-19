<?php

namespace Aurora\Filament\Concerns;

use Aurora\Blocks\Block;
use Aurora\Blocks\Blocks;
use CarlJanzell\FilamentPageBuilder\Support\EmbedUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/**
 * What Aurora adds to the page builder's canvas, wherever blocks are built on it (a page, a
 * shared section): the blocks' pictures in the block list, inspector fields that update the
 * canvas straight away, typing into list items on the canvas, links dropped onto embeds, and
 * asking before unsaved changes are left behind.
 */
trait EditsOnCanvas
{
    /**
     * The block list, with each block's picture (uploaded in Settings › Blocks, else its own).
     *
     * @return array<int, array{type: string, label: string, icon: ?string, category: string, description: ?string, preview: ?string}>
     */
    public function getPaletteProperty(): array
    {
        return array_map(fn (array $item): array => [...$item, 'preview' => Blocks::preview($item['type'])], parent::getPaletteProperty());
    }

    public function exitLabel(): string
    {
        return __('Exit');
    }

    /**
     * No "Form editor" button in the toolbar: content is edited on the canvas.
     */
    public function formEditorUrl(): ?string
    {
        return null;
    }

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
                $url = (string) $this->exitUrl();
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

                    // Not saved (e.g. someone else saved it meanwhile): stay, the notice says why.
                    if ($this->isDirty) {
                        return;
                    }
                }

                $leave($arguments);
            });
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

    /**
     * A field typed into on the canvas: the block's own (`title`), or one inside a list
     * item (`items.2.question`).
     */
    public function setBlockField(string $id, string $field, mixed $value): void
    {
        if (! str_contains($field, '.')) {
            parent::setBlockField($id, $field, $value);

            return;
        }

        $this->setBlockItemField($id, $field, $value);
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
