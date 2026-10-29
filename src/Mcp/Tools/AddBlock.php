<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\BlockInput;
use Aurora\Mcp\PagePresenter;
use Aurora\Models\User;
use Aurora\Support\BlockOutline;
use Aurora\Support\BlockTreeBuilder;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_block')]
#[Description('Insert a block (it may carry nested children) into a page: at the top level, or inside a container block\'s slot. Position 0 is first; omit it to append at the end.')]
class AddBlock extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id, or its address ("/docs/getting-started").')->required(),
            'block' => $schema->object()->description('The block as nested JSON: {"type": "...", "data": {...}, "children": ...}.')->required(),
            'parent_id' => $schema->string()->description('Id of the container block to insert into. Omit for the top level of the page.'),
            'slot' => $schema->string()->description('Slot within the parent, e.g. "content" for a band, "items" for a grid, "col-1" for the second column of a section. Defaults to the parent\'s first slot.'),
            'position' => $schema->integer()->description('0-based position among the siblings.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        $blocks = BlockTree::hydrate($page->blocks ?? []);
        [$parent, $slot] = static::target($blocks, $request->get('parent_id'), $request->get('slot'));

        $node = BlockInput::tree([BlockInput::decode($request->get('block'))])[0];
        $depth = $parent === null ? 1 : BlockTree::depthOf($blocks, $parent) + 1;
        $flat = BlockTreeBuilder::flattenNode($node, $parent, $slot, 0, $depth);

        $root = array_shift($flat);
        $position = $request->get('position');
        $next = BlockTree::insert($blocks, $root, is_numeric($position) ? (int) $position : null, $parent, $slot);
        $next = BlockTree::hydrate([...$next, ...$flat]);

        $page = $this->savePage($page, ['blocks' => $next], 'Added a '.$root['type'].' block');

        return Response::json([
            'message' => 'Block added.',
            'block_id' => $root['id'],
            'outline' => PagePresenter::outlineText(BlockOutline::for($page->blocks)),
            'preview' => $page->previewUrl(120),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{0: ?string, 1: ?string}
     */
    public static function target(array $blocks, mixed $parentId, mixed $slot): array
    {
        if (blank($parentId)) {
            return [null, null];
        }

        $parent = BlockTree::find($blocks, (string) $parentId);

        if ($parent === null) {
            throw new InvalidArgumentException("No block with id \"{$parentId}\" on this page. Use get_page to see block ids.");
        }

        $slots = app(BlockRegistries::class)->for('admin')->slots($parent['type'], $parent['data'] ?? []);

        if ($slots === []) {
            throw new InvalidArgumentException("A \"{$parent['type']}\" block cannot contain other blocks. Insert next to it instead (use its parent_id).");
        }

        $slot = filled($slot) ? (string) $slot : $slots[0];

        if (! in_array($slot, $slots, true)) {
            throw new InvalidArgumentException("The \"{$parent['type']}\" block has no slot \"{$slot}\". Its slots are: ".implode(', ', $slots).'.');
        }

        return [$parent['id'], $slot];
    }
}
