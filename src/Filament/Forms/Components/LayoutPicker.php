<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\Layout;
use Aurora\Models\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;

/**
 * Pick a page's layout by how it looks: each layout as a card with a thumbnail of the page
 * drawn with it (its preview link, scaled down in a frame), its name and what it's for. A
 * new page, with nothing to draw yet, shows the homepage. Its state is the layout's id.
 *
 * ->inModal() shows the chosen layout (its icon and name), with a button that opens the cards
 * in a dialog.
 */
class LayoutPicker extends Field
{
    protected string $view = 'aurora::filament.forms.components.layout-picker';

    protected bool $isInModal = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default(fn (): ?string => Layout::default()?->getKey());
        $this->in(fn (): array => Layout::cached()->modelKeys());

        $this->registerActions([
            fn (LayoutPicker $component): Action => $component->getPickAction(),
        ]);
    }

    public function inModal(bool $condition = true): static
    {
        $this->isInModal = $condition;

        return $this;
    }

    public function isInModal(): bool
    {
        return $this->isInModal;
    }

    /**
     * The chosen layout, else the default (a page without one is drawn with it).
     */
    public function getChosenLayout(): ?Layout
    {
        $id = $this->getState();

        return Layout::cached()->firstWhere('id', $id) ?? Layout::default();
    }

    /**
     * Every layout, in order.
     *
     * @return array<int, Layout>
     */
    public function getLayouts(): array
    {
        return Layout::cached()->all();
    }

    /**
     * The page the thumbnails draw: the one being edited, else the homepage.
     */
    public function getPreviewPage(): ?Page
    {
        $record = $this->getRecord();

        return $record instanceof Page && $record->exists ? $record : Page::query()->homepage()->first();
    }

    /**
     * A signed link to the page drawn with the layout, for its thumbnail.
     */
    public function getThumbnailUrl(Layout $layout): ?string
    {
        return $this->getPreviewPage()?->previewUrl(embed: true, layout: $layout->getKey());
    }

    /**
     * "Change": the cards in a dialog, for ->inModal().
     */
    public function getPickAction(): Action
    {
        return Action::make('pickLayout')
            ->label(__('Change'))
            ->icon('phosphor-layout')
            ->color('gray')
            ->size('sm')
            ->modalHeading(__('Choose a layout'))
            ->modalDescription(__('How the page is drawn on the website.'))
            ->modalIcon('phosphor-layout')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel(__('Use layout'))
            ->fillForm(fn (): array => ['layout' => $this->getChosenLayout()?->getKey()])
            ->schema(fn (): array => [
                LayoutPicker::make('layout')
                    ->hiddenLabel()
                    ->required(),
            ])
            ->action(function (array $data, LayoutPicker $component): void {
                $component->state($data['layout']);
                $component->callAfterStateUpdated();
            });
    }
}
