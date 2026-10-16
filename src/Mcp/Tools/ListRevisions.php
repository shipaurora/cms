<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\PageRevision;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_revisions')]
#[Description('The change history of a page: who changed what, when, and from where (visual editor, page settings, AI assistant). Use restore_revision to go back.')]
#[IsReadOnly]
class ListRevisions extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['page' => $schema->string()->description('Page id or slug.')->required()];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);

        return Response::json([
            'page' => $page->title,
            'revisions' => $page->revisions()->with('user')->limit(30)->get()->map(fn (PageRevision $revision) => [
                'id' => $revision->id,
                'when' => $revision->created_at->toIso8601String(),
                'ago' => $revision->created_at->diffForHumans(),
                'by' => $revision->user?->name,
                'source' => $revision->source->getLabel(),
                'summary' => $revision->summary,
                'blocks' => $revision->blockCount(),
            ]),
        ]);
    }
}
