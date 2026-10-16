<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\PagePresenter;
use Aurora\Models\Page;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('restore_revision')]
#[Description('Bring a page back to an earlier revision (content, title, address and SEO). The current state stays in the history, so this can itself be undone.')]
class RestoreRevision extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug.')->required(),
            'revision_id' => $schema->string()->description('From list_revisions.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        $revision = $page->revisions()->find($request->get('revision_id'))
            ?? throw new InvalidArgumentException('That revision does not belong to this page. Use list_revisions.');

        $page = $this->savePage($page, Page::attributesFromSnapshot($revision->snapshot), 'Restored the version from '.$revision->created_at->format('M j, H:i'));

        return Response::json(['message' => 'Restored.', 'links' => PagePresenter::links($page)]);
    }
}
