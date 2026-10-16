<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\PagePresenter;
use Aurora\Models\User;
use Aurora\Support\BlockOutline;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('move_block')]
#[Description('Move a block (with everything inside it) to another position, another container or another slot. Use duplicate=true to copy it there instead.')]
class MoveBlock extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug.')->required(),
            'block_id' => $schema->string()->required(),
            'parent_id' => $schema->string()->description('Container to move into. Omit for the top level.'),
            'slot' => $schema->string()->description('Slot in the parent; defaults to its first slot.'),
            'position' => $schema->integer()->description('0-based position among the new siblings.')->required(),
            'duplicate' => $schema->boolean()->description('Copy instead of move.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        $blocks = BlockTree::hydrate($page->blocks ?? []);
        $id = (string) $request->get('block_id');

        if (BlockTree::find($blocks, $id) === null) {
            throw new InvalidArgumentException('No block with that id on this page. Use get_page to see block ids.');
        }

        if ($request->get('duplicate')) {
            $original = BlockTree::find($blocks, $id);
            $existing = array_column($blocks, 'id');
            $blocks = BlockTree::hydrate(BlockTree::duplicate($blocks, $id));

            // The copy's root is the one new block that sits where the original sits.
            $copy = collect($blocks)->first(fn (array $block): bool => ! in_array($block['id'], $existing, true)
                && $block['parent'] === $original['parent'] && $block['slot'] === $original['slot']);
            $id = $copy['id'] ?? $id;
        }

        [$parent, $slot] = AddBlock::target($blocks, $request->get('parent_id'), $request->get('slot'));

        if ($parent !== null && BlockTree::isInvalidParent($blocks, $id, $parent)) {
            throw new InvalidArgumentException('A block cannot be moved inside itself.');
        }

        if (BlockTree::wouldExceedDepth($blocks, $id, $parent)) {
            throw new InvalidArgumentException('That would nest blocks more than '.BlockTree::MAX_DEPTH.' levels deep.');
        }

        $next = BlockTree::move($blocks, $id, (int) $request->get('position'), $parent, $slot);
        $page = $this->savePage($page, ['blocks' => $next], $request->get('duplicate') ? 'Duplicated a block' : 'Moved a block');

        return Response::json([
            'message' => $request->get('duplicate') ? 'Block duplicated.' : 'Block moved.',
            'block_id' => $id,
            'outline' => PagePresenter::outlineText(BlockOutline::for($page->blocks)),
        ]);
    }
}
