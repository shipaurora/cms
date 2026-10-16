<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_page')]
#[Description('Move a page to the trash. It can be restored by an editor in the admin panel. The homepage cannot be deleted.')]
#[IsDestructive]
class DeletePage extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['page' => $schema->string()->description('Page id or slug.')->required()];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        Gate::authorize('delete', $page);

        $page->delete();
        $this->did('Moved to trash: '.$page->title, $page);

        return Response::text("\"{$page->title}\" is in the trash. An editor can restore it from the Pages list (Trashed filter).");
    }
}
