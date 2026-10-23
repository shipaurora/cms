<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\SeoChecks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('seo_report')]
#[Description('What would help pages in search results: a missing or too long title or meta description, a missing main heading or more than one H1 (anywhere on the page as drawn, rich text included), heading levels skipped, thin content, links that lead nowhere or through a redirect, links without text, images without alt text, a missing share image, titles or descriptions another page shares. With "page": that page\'s issues (including unpublished changes); without: every live page that has issues. Fix them with update_page and update_block.')]
#[IsReadOnly]
class SeoReport extends CmsTool
{
    public function shouldRegister(Request $request): bool
    {
        return (bool) $this->actingUser($request)?->can('viewAny', Page::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug. Leave out for every live page.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        if (filled($request->get('page'))) {
            $page = $this->findPage($request);

            return Response::json(['page' => $page->id, 'title' => $page->title, 'issues' => SeoChecks::for($page)]);
        }

        return Response::json([
            'pages' => array_map(fn (array $row): array => [
                'page' => $row['page']->id,
                'title' => $row['page']->title,
                'path' => $row['page']->path(),
                'issues' => array_column($row['issues'], 'message'),
            ], SeoChecks::report()),
        ]);
    }
}
