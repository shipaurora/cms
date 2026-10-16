<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Layout;
use Aurora\Models\Page;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_layouts')]
#[Description('The layouts a page can be drawn with (pass the handle as "layout" to create_page or update_page), with what each is for. Pages without one use the default.')]
#[IsReadOnly]
class ListLayouts extends CmsTool
{
    protected bool $needsSite = false;

    public function shouldRegister(Request $request): bool
    {
        return (bool) $this->actingUser($request)?->can('viewAny', Page::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response
    {
        return Response::json([
            'layouts' => Layout::cached()->map(fn (Layout $layout): array => [
                'handle' => $layout->handle,
                'name' => $layout->name,
                'description' => $layout->description,
                'default' => $layout->is_default,
            ])->values()->all(),
        ]);
    }
}
