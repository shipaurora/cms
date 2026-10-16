<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('remove_menu_item')]
#[Description('Remove a menu item and its children from a menu. The page it links to is not affected.')]
#[IsDestructive]
class RemoveMenuItem extends CmsTool
{
    use ManagesMenus;

    public function schema(JsonSchema $schema): array
    {
        return ['item_id' => $schema->string()->required()];
    }

    protected function run(Request $request, User $user): Response
    {
        $item = $this->findItem((string) $request->get('item_id'));
        $menu = $item->menu;
        $title = $item->title;
        $item->delete();

        $this->did("Removed \"{$title}\" from the {$menu->name} menu", $menu);

        return Response::text($this->summary.'.');
    }
}
