<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Menu;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_menus')]
#[Description('The website\'s menus (main navigation, footer, legal links) with their nested items. Items link to pages by id, or to URLs.')]
#[IsReadOnly]
class GetMenus extends CmsTool
{
    use ManagesMenus;

    public function shouldRegister(Request $request): bool
    {
        return (bool) $this->actingUser($request)?->can('view-any Menu');
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response
    {
        return Response::json([
            'menus' => Menu::query()->with(['locations', 'menuItems'])->get()->map(fn (Menu $menu) => [
                'id' => $menu->id,
                'name' => $menu->name,
                'locations' => $this->locations($menu),
                'items' => $menu->menuItems->map(fn ($item) => $this->presentItem($item))->all(),
            ]),
            'notes' => 'The main navigation (location "header") supports one level of dropdown children. Footer menus use top-level items as column headings.',
        ]);
    }
}
