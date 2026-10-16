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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('remove_block')]
#[Description('Remove a block and everything inside it from a page. Recoverable from the page history.')]
#[IsDestructive]
class RemoveBlock extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug.')->required(),
            'block_id' => $schema->string()->required(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        $blocks = BlockTree::hydrate($page->blocks ?? []);
        $block = BlockTree::find($blocks, (string) $request->get('block_id'));

        if ($block === null) {
            throw new InvalidArgumentException('No block with that id on this page. Use get_page to see block ids.');
        }

        $removed = count(BlockTree::descendantIds($blocks, $block['id'])) + 1;
        $page = $this->savePage($page, ['blocks' => BlockTree::remove($blocks, $block['id'])], 'Removed a '.$block['type'].' block');

        return Response::json([
            'message' => "Removed {$removed} block(s).",
            'outline' => PagePresenter::outlineText(BlockOutline::for($page->blocks)),
        ]);
    }
}
