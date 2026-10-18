<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\Page;
use Aurora\Models\SharedSection;
use Aurora\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_shared_sections')]
#[Description('Shared sections: blocks built once and shown on many pages. Place one on a page with the "shared-section" block (its id as shared_section_id); its content is edited in the admin under Content › Shared sections, and changes everywhere it is shown.')]
#[IsReadOnly]
class ListSharedSections extends CmsTool
{
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
            'shared_sections' => SharedSection::query()->orderBy('name')->get()->map(fn (SharedSection $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'blocks' => count($section->blocks ?? []),
                'shown_on_pages' => $section->pages()->count(),
            ])->all(),
        ]);
    }
}
