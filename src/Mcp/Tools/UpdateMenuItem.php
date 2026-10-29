<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\MenuItem;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_menu_item')]
#[Description('Rename, relink, nest, un-nest or reorder a menu item. Only the fields you pass change. Pass parent_id "" to move an item to the top level.')]
class UpdateMenuItem extends CmsTool
{
    use ManagesMenus;

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->string()->required(),
            'title' => $schema->string(),
            'page' => $schema->string()->description('Page id, or its address, to link to instead.'),
            'url' => $schema->string()->description('URL to link to instead.'),
            'parent_id' => $schema->string()->description('New parent item id, or "" for top level.'),
            'position' => $schema->integer()->description('0-based position among siblings.'),
            'new_tab' => $schema->boolean(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $item = $this->findItem((string) $request->get('item_id'));
        $changes = [];

        if (filled($request->get('title'))) {
            $changes['title'] = $request->get('title');
        }

        if (filled($request->get('page')) || filled($request->get('url'))) {
            $changes = [...$changes, ...$this->linkAttributes($request->get('page'), $request->get('url'))];
        }

        if ($request->get('new_tab') !== null) {
            $changes['target'] = $request->get('new_tab') ? '_blank' : '_self';
        }

        if ($request->get('parent_id') !== null) {
            $parent = filled($request->get('parent_id')) ? $this->findItem((string) $request->get('parent_id')) : null;

            // In the same menu, and never under itself: a loop would never finish rendering.
            if ($parent !== null && ($parent->menu_id !== $item->menu_id || $parent->is($item) || in_array($parent->id, $this->descendantIds($item), true))) {
                throw new InvalidArgumentException('An item can only go under another item of the same menu, and not under itself or its own children.');
            }

            $changes['parent_id'] = $parent?->id;
        }

        $item->update($changes);

        if ($request->get('position') !== null || array_key_exists('parent_id', $changes)) {
            $this->place($item, is_numeric($request->get('position')) ? (int) $request->get('position') : null);
        }

        $this->did("Updated menu item \"{$item->title}\"", $item->menu);

        return Response::json(['message' => $this->summary, 'item' => $this->presentItem($item->refresh())]);
    }

    /**
     * @return array<int, string>
     */
    protected function descendantIds(MenuItem $item): array
    {
        $ids = [];
        $level = [$item->id];

        while ($level !== []) {
            $level = MenuItem::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }
}
