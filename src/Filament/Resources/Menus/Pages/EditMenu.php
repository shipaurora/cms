<?php

namespace Aurora\Filament\Resources\Menus\Pages;

use Aurora\Filament\Actions\LanguageSwitcher;
use Aurora\Filament\Concerns\BreadcrumbsToList;
use Aurora\Filament\Concerns\ConfirmsLeaving;
use Aurora\Filament\Concerns\SavesFromHeader;
use Aurora\Filament\Forms\Components\PagePicker;
use Aurora\Filament\Forms\Components\TranslatedField;
use Aurora\Livewire\MenuItems;
use Aurora\Models\MenuItem as MenuItemModel;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Aurora\Support\SafeUrl;
use Closure;
use Datlechin\FilamentMenuBuilder\Contracts\MenuPanel;
use Datlechin\FilamentMenuBuilder\Enums\LinkTarget;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Datlechin\FilamentMenuBuilder\Resources\MenuResource\Pages\EditMenu as BaseEditMenu;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editing a menu: its name and visibility, then the items. New items are added from an
 * "Add" menu above the list, where each kind (pages, shortcuts, a custom link or a text
 * label) opens in a modal, instead of the package's panels permanently beside the list.
 */
class EditMenu extends BaseEditMenu
{
    use BreadcrumbsToList;
    use ConfirmsLeaving;
    use SavesFromHeader;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            Section::make(__('Menu items'))
                ->key('menuItems')
                ->icon('phosphor-text-align-left')
                ->description(__('Drag items to reorder them, or onto another item to nest them.'))
                ->headerActions([$this->addItemActions()])
                ->schema([
                    Livewire::make('menu-builder-items', ['menu' => $this->getRecord()])->key('menu-builder-items'),
                ]),
        ]);
    }

    protected function addItemActions(): ActionGroup
    {
        $plugin = FilamentMenuBuilderPlugin::get();

        $panels = collect($plugin->getMenuPanels())
            ->values()
            ->map(fn (MenuPanel $panel): Action => $this->addFromPanelAction($panel))
            ->all();

        $custom = array_values(array_filter([
            $plugin->isShowCustomLinkPanel() ? $this->addCustomLinkAction() : null,
            $plugin->isShowCustomTextPanel() ? $this->addCustomTextAction() : null,
        ]));

        return ActionGroup::make([
            ActionGroup::make($panels)->dropdown(false),
            ActionGroup::make($custom)->dropdown(false),
        ])
            ->label(__('Add'))
            ->icon('phosphor-plus-bold')
            ->button()
            ->dropdownPlacement('bottom-end');
    }

    /**
     * Pick one of a panel's items (a page, a shortcut, …) to append to the menu.
     */
    protected function addFromPanelAction(MenuPanel $panel): Action
    {
        return Action::make('addFrom'.Str::studly($panel->getIdentifier()))
            ->label($this->panelItemName($panel))
            ->icon($panel->getIcon())
            ->modalHeading(__('Add :item', ['item' => Str::lower($this->panelItemName($panel))]))
            ->modalDescription(filled($panel->getDescription()) ? __($panel->getDescription()) : null)
            ->modalIcon($panel->getIcon())
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Add to menu'))
            ->registerModalActions([$this->confirmDuplicateAction()])
            ->schema(fn (): array => $this->isPagePanel($panel) ? [
                PagePicker::make('page')
                    ->hiddenLabel()
                    ->badge(fn (Page $page): ?string => $this->isInMenu(['linkable_type' => $page->getMorphClass(), 'linkable_id' => $page->getKey()]) ? __('In this menu') : null)
                    ->live()
                    ->required()
                    ->validationMessages(['required' => __('Choose a page to add.')]),
                // A page with subpages: just the page, or the whole tree as nested items.
                ToggleButtons::make('subpages')
                    ->label(__('Subpages'))
                    ->options(['single' => __('Only the page'), 'tree' => __('With its subpages')])
                    ->icons(['single' => 'phosphor-file-text', 'tree' => 'phosphor-tree-structure'])
                    ->default('single')
                    ->inline()
                    ->helperText(__('With its subpages, the menu mirrors the pages below it, nested the same way.'))
                    ->visible(fn (Get $get): bool => filled($get('page')) && Page::query()->where('parent_id', $get('page'))->exists()),
            ] : [
                Radio::make('item')
                    ->hiddenLabel()
                    ->options(fn (): array => array_column($this->panelItems($panel), 'title'))
                    ->descriptions(fn (): array => array_filter(array_map(
                        fn (array $item): ?string => $this->isInMenu($item) ? __('In this menu') : null,
                        $this->panelItems($panel),
                    )))
                    ->required()
                    ->validationMessages(['required' => __('Choose one to add.')]),
            ])
            ->action(function (array $data, Action $action) use ($panel): void {
                $items = $this->itemsToAdd($panel, $data);

                // Already in the menu: asked first, since it would be in it twice. The question
                // opens over this dialog, which stays open behind it to go back to.
                if ($items !== [] && $this->isInMenu($items[0])) {
                    $this->mountAction('confirmDuplicate', [
                        'panel' => $panel->getIdentifier(),
                        'data' => Arr::only($data, ['page', 'subpages', 'item']),
                    ]);

                    $action->halt();
                }

                $this->appendItems($items);
            });
    }

    /**
     * "Docs is already in this menu": adding it again gives the menu a second link to it.
     */
    protected function confirmDuplicateAction(): Action
    {
        $panel = fn (array $arguments): ?MenuPanel => collect(FilamentMenuBuilderPlugin::get()->getMenuPanels())
            ->first(fn (MenuPanel $panel): bool => $panel->getIdentifier() === ($arguments['panel'] ?? null));
        $items = fn (array $arguments): array => ($found = $panel($arguments)) ? $this->itemsToAdd($found, $arguments['data'] ?? []) : [];

        return Action::make('confirmDuplicate')
            ->label(__('Add it again'))
            ->requiresConfirmation()
            ->color('warning')
            ->modalIcon('phosphor-copy')
            ->modalHeading(fn (array $arguments): string => __('“:title” is already in this menu', ['title' => $this->itemTitle($items($arguments)[0] ?? [])]))
            ->modalDescription(__('Adding it again puts a second link to it in the menu.'))
            ->modalSubmitActionLabel(__('Add it again'))
            // Added: the "Add" dialog behind it closes too. Cancelled: back to it.
            ->cancelParentActions()
            ->overlayParentActions()
            ->action(fn (array $arguments) => $this->appendItems($items($arguments)));
    }

    /**
     * What picking in a panel adds: the page (with its subpages, if asked for), or the item.
     * A page's item starts without a title of its own: it shows the page's, in every language.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function itemsToAdd(MenuPanel $panel, array $data): array
    {
        if ($this->isPagePanel($panel)) {
            $page = Page::query()->find($data['page'] ?? null);

            return $page === null ? [] : [[
                'title' => [],
                'linkable_type' => $page->getMorphClass(),
                'linkable_id' => $page->getKey(),
                'panel' => $page->getMorphClass(),
                'children' => ($data['subpages'] ?? 'single') === 'tree' ? $this->subpageItems($page->getKey()) : [],
            ]];
        }

        $item = $this->panelItems($panel)[(int) ($data['item'] ?? -1)] ?? null;

        return $item === null ? [] : [[...$item, 'panel' => $item['linkable_type'] ?? $panel->getIdentifier()]];
    }

    /**
     * An item to add, by name: its page's title, or its own.
     *
     * @param  array<string, mixed>  $item
     */
    protected function itemTitle(array $item): string
    {
        return isset($item['linkable_id'])
            ? (string) Page::query()->find($item['linkable_id'])?->title
            : (string) (is_array($item['title'] ?? null) ? reset($item['title']) : ($item['title'] ?? ''));
    }

    protected function addCustomLinkAction(): Action
    {
        return Action::make('addCustomLink')
            ->label(__('Custom link'))
            ->icon('phosphor-link')
            ->modalHeading(__('Add a custom link'))
            ->modalDescription(__('To another site, a file, or an address on this site that isn’t a page.'))
            ->modalIcon('phosphor-link')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Add to menu'))
            ->fillForm(fn (): array => ['target' => LinkTarget::Self->value, 'title_language' => $this->menuLocale()])
            ->schema([
                static::titleField(TextInput::make('title')->label(__('Label'))->required()->maxLength(255)),
                TextInput::make('url')->label(__('URL'))->placeholder('https://… or /path')->required()->maxLength(2048)->rule(SafeUrl::rule()),
                Select::make('target')->label(__('Open in'))->options(LinkTarget::class)->required(),
                Section::make(__('Advanced'))
                    ->collapsed()
                    ->compact()
                    ->schema([
                        MenuItems::iconField(),
                        TextInput::make('classes')->label(__('CSS classes')),
                        TextInput::make('rel')->placeholder(__('nofollow noopener')),
                    ]),
            ])
            ->action(fn (array $data) => $this->appendItems([[
                'title' => static::titleValue($data['title']),
                'url' => $data['url'],
                'target' => $data['target'] instanceof LinkTarget ? $data['target']->value : $data['target'],
                'icon' => $data['icon'] ?? null,
                'classes' => $data['classes'] ?? null,
                'rel' => $data['rel'] ?? null,
            ]]));
    }

    protected function addCustomTextAction(): Action
    {
        return Action::make('addCustomText')
            ->label(__('Text label'))
            ->icon('phosphor-text-align-left')
            ->modalHeading(__('Add a text label'))
            ->modalDescription(__('A heading without a link, for example to group the items nested under it.'))
            ->modalIcon('phosphor-text-align-left')
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('Add to menu'))
            ->fillForm(fn (): array => ['title_language' => $this->menuLocale()])
            ->schema([
                static::titleField(TextInput::make('title')->label(__('Text'))->required()->maxLength(255)),
                MenuItems::iconField(),
            ])
            ->action(fn (array $data) => $this->appendItems([['title' => static::titleValue($data['title']), 'icon' => $data['icon'] ?? null]]));
    }

    /**
     * With more than one website language, a title is written per language, with a language
     * picker above it (as in the item's own edit form).
     */
    public static function titleField(TextInput $field, ?Closure $placeholder = null): TextInput|Group
    {
        $plugin = FilamentMenuBuilderPlugin::get();

        if ($plugin->isTranslatable() && in_array('title', $plugin->getTranslatableMenuItemFields(), true)) {
            return TranslatedField::make($field, $plugin->getTranslatableLocales(), $placeholder);
        }

        return $placeholder === null ? $field : $field->placeholder($placeholder(ContentLocale::default()));
    }

    /**
     * The titles typed in, without the languages left empty (they show the default one).
     *
     * @param  string|array<string, ?string>  $title
     * @return string|array<string, string>
     */
    protected static function titleValue(string|array $title): string|array
    {
        return is_array($title) ? array_filter($title, filled(...)) : $title;
    }

    /**
     * A panel's items with any URL closures resolved, as the package's own panel does.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function panelItems(MenuPanel $panel): array
    {
        return array_values(array_map(
            fn (array $item): array => isset($item['url']) && is_callable($item['url']) ? [...$item, 'url' => $item['url']()] : $item,
            $panel->getItems(),
        ));
    }

    /**
     * Whether the panel lists pages: those are picked from the page tree.
     */
    protected function isPagePanel(MenuPanel $panel): bool
    {
        return $panel->getIdentifier() === 'pages'
            || collect($this->panelItems($panel))->contains(fn (array $item): bool => ($item['linkable_type'] ?? null) === (new Page)->getMorphClass());
    }

    /**
     * One of the panel's items, by what it holds: "Page", "Shortcut".
     */
    protected function panelItemName(MenuPanel $panel): string
    {
        return match ($panel->getIdentifier()) {
            'pages' => __('Page'),
            'shortcuts' => __('Shortcut'),
            default => __($panel->getName()),
        };
    }

    /**
     * A page's subpages as menu items, each with its own, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function subpageItems(string $pageId): array
    {
        return Page::query()->where('parent_id', $pageId)->oldest()->get()
            ->map(fn (Page $page): array => [
                'title' => [],
                'linkable_type' => $page->getMorphClass(),
                'linkable_id' => $page->getKey(),
                'panel' => $page->getMorphClass(),
                'children' => $this->subpageItems($page->getKey()),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function isInMenu(array $item): bool
    {
        $items = $this->getRecord()->menuItems();

        return isset($item['linkable_id'])
            ? $items->where('linkable_type', $item['linkable_type'])->where('linkable_id', $item['linkable_id'])->exists()
            : $items->where('url', $item['url'] ?? null)->exists();
    }

    /**
     * Append items to the end of the menu and refresh the item list.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function appendItems(array $items): void
    {
        if ($items === []) {
            return;
        }

        $created = DB::transaction(function () use ($items): array {
            $order = $this->getRecord()->menuItems()->lockForUpdate()->max('order') ?? 0;

            $created = [];

            foreach ($items as $item) {
                $created[] = $this->createItem($item, ++$order);
            }

            return $created;
        });

        $this->dispatch('menu:changed');

        Notification::make()
            ->title(count($created) === 1 ? __('Added “:title”', ['title' => $created[0]->title]) : trans_choice('Added :count item|Added :count items', count($created)))
            ->success()
            ->send();
    }

    /**
     * One item, and the items nested under it ("children"), in their order.
     *
     * @param  array<string, mixed>  $item
     */
    protected function createItem(array $item, int $order, ?string $parentId = null): MenuItemModel
    {
        $created = $this->getRecord()->menuItems()->create([...Arr::except($item, 'children'), 'parent_id' => $parentId, 'order' => $order]);

        foreach (array_values($item['children'] ?? []) as $index => $child) {
            $this->createItem($child, $index + 1, $created->getKey());
        }

        return $created;
    }

    /**
     * Menus › the menu being edited ("Main navigation").
     *
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();

        return [
            $resource::getUrl('index') => $resource::getBreadcrumb(),
            $this->getRecord()->name,
        ];
    }

    /**
     * The language the menu is shown and edited in (the language switcher in the header).
     */
    public ?string $activeLocale = null;

    /**
     * The website's languages, the default first, once there is more than one.
     *
     * @return array<int, string>
     */
    public function getTranslatableLocales(): array
    {
        $plugin = FilamentMenuBuilderPlugin::get();

        return $plugin->isTranslatable() ? $plugin->getTranslatableLocales() : [];
    }

    public function menuLocale(): string
    {
        return $this->activeLocale ?? $this->getTranslatableLocales()[0] ?? ContentLocale::default();
    }

    /**
     * The item list follows: titles in that language, and its edit dialog opens in it.
     */
    public function updatedActiveLocale(): void
    {
        $this->dispatch('menu:locale', locale: $this->menuLocale());
    }

    protected function getHeaderActions(): array
    {
        return [
            LanguageSwitcher::make()->visible(fn (): bool => count($this->getTranslatableLocales()) > 1),
            ...parent::getHeaderActions(),
            $this->headerSaveButton(),
        ];
    }
}
