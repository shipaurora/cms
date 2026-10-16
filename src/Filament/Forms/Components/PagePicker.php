<?php

namespace Aurora\Filament\Forms\Components;

use Aurora\Models\Page;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pick one page, from the page tree: subpages indented under their page, each with its
 * address below its title, in a box of its own that scrolls, with a search above it once
 * there are more than a few pages. Its state is the page's id. Used wherever a page is
 * picked: menus, block links, links in text.
 *
 * Where there's little room (the canvas sidebar), ->inModal() shows the chosen page, like
 * a chosen file, with a button that opens the list in a dialog.
 */
class PagePicker extends Field
{
    protected string $view = 'aurora::filament.forms.components.page-picker';

    /**
     * A short note shown on a page's row ("In this menu"), or null for none.
     */
    protected ?Closure $badge = null;

    /**
     * Narrows the pages offered.
     */
    protected ?Closure $modifyQuery = null;

    protected bool $isInModal = false;

    /**
     * With more pages than this, a search goes above the list.
     */
    public const SEARCHABLE_FROM = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerActions([
            fn (PagePicker $component): Action => $component->getPickAction(),
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
     * The chosen page, if it still exists.
     */
    public function getChosenPage(): ?Page
    {
        $id = $this->getState();

        return is_string($id) && $id !== '' ? Page::query()->find($id) : null;
    }

    /**
     * "Choose page" (or "Change"): the list in a dialog, for ->inModal().
     */
    public function getPickAction(): Action
    {
        return Action::make('pickPage')
            ->label(fn (): string => $this->getChosenPage() ? __('Change') : __('Choose page'))
            ->icon('phosphor-file-text')
            ->color('gray')
            ->size('sm')
            ->modalHeading(__('Choose a page'))
            ->modalIcon('phosphor-file-text')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Use page'))
            ->fillForm(fn (): array => ['page' => $this->getChosenPage()?->getKey()])
            ->schema(fn (): array => [
                PagePicker::make('page')
                    ->hiddenLabel()
                    ->badge($this->badge)
                    ->modifyQueryUsing($this->modifyQuery)
                    ->required()
                    ->validationMessages(['required' => __('Choose a page.')]),
            ])
            ->action(function (array $data, PagePicker $component): void {
                $component->state($data['page']);
                $component->callAfterStateUpdated();
                static::refreshCanvas($component);
            });
    }

    #[ExposedLivewireMethod]
    public function clearPage(): void
    {
        $this->state(null);
        $this->callAfterStateUpdated();
        static::refreshCanvas($this);
    }

    /**
     * In the canvas editor, the block's preview follows the change straight away.
     */
    protected static function refreshCanvas(PagePicker $component): void
    {
        $livewire = $component->getLivewire();

        if (method_exists($livewire, 'commitSelectedBlock')) {
            $livewire->commitSelectedBlock();
        }
    }

    public function badge(?Closure $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQuery = $callback;

        return $this;
    }

    /**
     * The pages in tree order: the homepage first, then the rest by title, each followed by
     * its subpages (whose parent isn't offered, they go at the top level).
     *
     * @return array<int, array{id: string, title: string, path: string, depth: int, badge: ?string}>
     */
    public function getPageRows(): array
    {
        $pages = Page::query()
            ->when($this->modifyQuery, fn (Builder $query): mixed => $this->evaluate($this->modifyQuery, ['query' => $query]))
            ->get(['id', 'title', 'slug', 'parent_id', 'is_homepage', 'locales'])
            ->keyBy(fn (Page $page): string => $page->getKey());
        $children = $pages->groupBy(fn (Page $page): string => isset($pages[$page->parent_id]) ? $page->parent_id : '');
        $rows = [];

        $walk = function (string $parent, int $depth) use (&$walk, &$rows, $children): void {
            /** @var Collection<int, Page> $siblings */
            $siblings = ($children[$parent] ?? collect())->sortBy(fn (Page $page): string => ($page->is_homepage ? '0' : '1').mb_strtolower($page->title));

            foreach ($siblings as $page) {
                $rows[] = [
                    'id' => $page->getKey(),
                    'title' => $page->title,
                    'path' => $page->path(),
                    'depth' => $depth,
                    'badge' => $this->badge ? $this->evaluate($this->badge, ['page' => $page], [Page::class => $page]) : null,
                ];
                $walk($page->getKey(), $depth + 1);
            }
        };
        $walk('', 0);

        return $rows;
    }
}
