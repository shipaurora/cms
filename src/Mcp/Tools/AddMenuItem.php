<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_menu_item')]
#[Description('Add an item to a menu: a link to a page (preferred, follows the page when its address changes), a URL, or a plain heading (no page and no url).')]
class AddMenuItem extends CmsTool
{
    use ManagesMenus;

    public function schema(JsonSchema $schema): array
    {
        return [
            'menu' => $schema->string()->description('Menu id, or a location: "header", "footer", "legal".')->required(),
            'title' => $schema->string()->description('Label. Defaults to the page title.'),
            'page' => $schema->string()->description('Page id or slug to link to.'),
            'url' => $schema->string()->description('A URL, when not linking to a page.'),
            'parent_id' => $schema->string()->description('Menu item to nest under (dropdown or footer column).'),
            'position' => $schema->integer()->description('0-based position among siblings. Appends when omitted.'),
            'new_tab' => $schema->boolean(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $menu = $this->findMenu((string) $request->get('menu'));
        $link = $this->linkAttributes($request->get('page'), $request->get('url'));
        $parent = filled($request->get('parent_id')) ? $this->findItem((string) $request->get('parent_id')) : null;

        if ($parent && $parent->menu_id !== $menu->id) {
            throw new InvalidArgumentException('The parent item belongs to another menu.');
        }

        $title = $request->get('title') ?: (filled($link['linkable_id']) ? Page::query()->find($link['linkable_id'])->title : null);

        if (blank($title)) {
            throw new InvalidArgumentException('Give the item a "title".');
        }

        $item = MenuItem::query()->create([
            'menu_id' => $menu->id,
            'parent_id' => $parent?->id,
            'title' => $title,
            'target' => $request->get('new_tab') ? '_blank' : '_self',
            'order' => 999,
            ...$link,
        ]);

        $this->place($item, is_numeric($request->get('position')) ? (int) $request->get('position') : null);
        $this->did("Added \"{$title}\" to the {$menu->name} menu", $menu);

        return Response::json(['message' => $this->summary, 'item' => $this->presentItem($item->refresh())]);
    }
}
