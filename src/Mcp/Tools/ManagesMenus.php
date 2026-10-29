<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Support\SafeUrl;
use Datlechin\FilamentMenuBuilder\Models\MenuLocation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Mcp\Request;

/**
 * Shared by the menu tools. Menus are decoupled from pages: an item links to a page by
 * id (and follows it when its address changes) or to any URL.
 */
trait ManagesMenus
{
    public function shouldRegister(Request $request): bool
    {
        return (bool) $this->actingUser($request)?->can('update Menu');
    }

    protected function findMenu(string $identifier): Menu
    {
        $menu = Menu::query()->whereKey($identifier)->first()
            ?? Menu::query()->whereHas('locations', fn ($query) => $query->where('location', $identifier))->first()
            ?? Menu::query()->where('name', $identifier)->first();

        return $menu ?? throw new InvalidArgumentException("No menu \"{$identifier}\". Use get_menus to see the menus and their locations (".NavigationLocation::cached()->pluck('handle')->implode(', ').').');
    }

    protected function findItem(string $id): MenuItem
    {
        return MenuItem::query()->find($id) ?? throw new InvalidArgumentException('No menu item with that id. Use get_menus.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function linkAttributes(?string $pageId, ?string $url): array
    {
        if (filled($pageId)) {
            // By id, or by its address in the content language.
            $page = (Str::isUuid($pageId) ? Page::query()->find($pageId) : null)
                ?? Page::findByPath($pageId)
                ?? throw new InvalidArgumentException("No page \"{$pageId}\". Use list_pages.");

            return ['linkable_type' => $page->getMorphClass(), 'linkable_id' => $page->getKey(), 'url' => null];
        }

        if (filled($url) && ! SafeUrl::isSafe($url)) {
            throw new InvalidArgumentException('Use a web address (https://…), an address on the site (/…), mailto: or tel:.');
        }

        return ['linkable_type' => null, 'linkable_id' => null, 'url' => $url];
    }

    /**
     * Put an item at a position among its siblings and renumber them.
     */
    protected function place(MenuItem $item, ?int $position): void
    {
        $siblings = MenuItem::query()
            ->where('menu_id', $item->menu_id)
            ->where('parent_id', $item->parent_id)
            ->whereKeyNot($item->getKey())
            ->orderBy('order')
            ->get()
            ->values();

        $position = $position === null ? $siblings->count() : max(0, min($position, $siblings->count()));
        $siblings->splice($position, 0, [$item]);

        $siblings->each(fn (MenuItem $sibling, int $index) => $sibling->forceFill(['order' => $index + 1])->saveQuietly());
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentItem(MenuItem $item): array
    {
        return array_filter([
            'id' => $item->id,
            'title' => $item->title,
            'url' => $item->url,
            'page_id' => $item->linkable instanceof Page ? $item->linkable->id : null,
            'target' => $item->target?->value,
            'children' => $item->children->map(fn (MenuItem $child) => $this->presentItem($child))->all() ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array<int, string>
     */
    protected function locations(Menu $menu): array
    {
        return $menu->locations->map(fn (MenuLocation $location) => $location->location)->all();
    }
}
