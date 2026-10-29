<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\PagePresenter;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_page')]
#[Description('Read one page in full: settings, SEO, links (live, preview, visual editor) and its blocks as a nested tree with block ids. Use the block ids with update_block, move_block and remove_block.')]
#[IsReadOnly]
class GetPage extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id, or its address ("/docs/getting-started"; "/" or "home" for the homepage).')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);

        return Response::json(PagePresenter::full($page->load('author'), $user));
    }
}
