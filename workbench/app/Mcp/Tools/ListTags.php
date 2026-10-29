<?php

namespace Workbench\App\Mcp\Tools;

use Aurora\Mcp\Tools\CmsTool;
use Aurora\Models\AssetTag;
use Aurora\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A website's own tool, as one adds it with McpTools::register(): it acts as the person
 * who connected and checks their permission like Aurora's tools do.
 */
#[Name('list_tags')]
#[Description('List the tags of the media library.')]
#[IsReadOnly]
class ListTags extends CmsTool
{
    protected bool $needsSite = false;

    protected function run(Request $request, User $user): Response
    {
        if (! $user->can('view-any Asset')) {
            return Response::error('Permission denied: you may not see the media library.');
        }

        return Response::json(['tags' => AssetTag::query()->orderBy('name')->pluck('name')->all()]);
    }
}
