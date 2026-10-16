<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\PagePresenter;
use Aurora\Models\Page;
use Aurora\Models\User;
use Aurora\Support\ContentLocale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_pages')]
#[Description('List the pages of the website with their id, address, status (draft, review, scheduled, published) and whether you may edit them.')]
#[IsReadOnly]
class ListPages extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter by words in the title or address.'),
            'status' => $schema->string()->enum(['draft', 'review', 'published'])->description('Only pages with this status.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        Gate::authorize('viewAny', Page::class);

        $pages = Page::query()
            ->with('author')
            ->when($request->get('search'), fn ($query, $search) => $query->where(fn ($query) => $query->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when($request->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('is_homepage')
            ->orderBy('title->'.ContentLocale::current())
            ->get();

        return Response::json([
            'count' => $pages->count(),
            'pages' => $pages->map(fn (Page $page) => PagePresenter::summary($page, $user))->all(),
        ]);
    }
}
