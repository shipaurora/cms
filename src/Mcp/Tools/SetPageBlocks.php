<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\BlockInput;
use Aurora\Mcp\PagePresenter;
use Aurora\Models\User;
use Aurora\Support\BlockOutline;
use Aurora\Support\BlockTreeBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_page_blocks')]
#[Description('Replace ALL content of a page with a new nested block tree. Best for composing a page from scratch or a full redesign; for small changes prefer update_block, add_block, move_block or remove_block. The previous version stays restorable in the page history.')]
class SetPageBlocks extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug.')->required(),
            'blocks' => $schema->array()->items($schema->object())->description('The complete nested block tree. See list_block_types.')->required(),
            'summary' => $schema->string()->description('A short description of the change for the page history, e.g. "Redesigned the pricing section".'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        $blocks = BlockInput::decode($request->get('blocks'));
        $flat = BlockTreeBuilder::flatten(BlockInput::tree(is_array($blocks) ? $blocks : []));

        $page = $this->savePage($page, ['blocks' => $flat], $request->get('summary') ?: 'Rebuilt the content ('.count($flat).' blocks)');

        return Response::json([
            'message' => 'Content saved.',
            'links' => PagePresenter::links($page),
            'outline' => PagePresenter::outlineText(BlockOutline::for($page->blocks)),
        ]);
    }
}
