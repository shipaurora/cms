<?php

namespace Aurora\Mcp\Tools;

use Aurora\Models\User;
use Aurora\Support\BlockCatalogue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_block_types')]
#[Description('The catalogue of content blocks pages are built from: every field, option, slot, default and usage hint, plus the shared styling options. Read this before composing or editing page content. Pass "type" for one block in full detail.')]
#[IsReadOnly]
class ListBlockTypes extends CmsTool
{
    protected bool $needsSite = false;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Optional: one block type to describe, e.g. "hero".'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        if (filled($type = $request->get('type'))) {
            $block = BlockCatalogue::describe($type);

            return $block === []
                ? Response::error("Unknown block type \"{$type}\". Call list_block_types without arguments to see them all.")
                : Response::json($block);
        }

        return Response::json([
            'how_pages_work' => [
                'A page is a tree of blocks. Containers (band, section, grid, button-group) hold other blocks in named slots.',
                'A typical page: optional hero first, then a sequence of "band" blocks (full-width sections with a background). Inside a band: a heading, then a section (columns) or a grid (repeated items).',
                'Write blocks as nested JSON: {"type": "band", "data": {...}, "children": {"content": [ ...blocks ]}}. For single-slot containers a plain list works: "children": [ ...blocks ].',
                'Omitted data fields fall back to the block defaults. Unknown fields or invalid options are rejected with an explanation.',
            ],
            'styling' => BlockCatalogue::styling(),
            'blocks' => array_values(BlockCatalogue::available()),
        ]);
    }
}
