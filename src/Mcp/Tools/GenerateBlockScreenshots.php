<?php

namespace Aurora\Mcp\Tools;

use Aurora\Blocks\Blocks;
use Aurora\Models\User;
use Aurora\Support\BlockScreenshots;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

#[Name('generate_block_screenshots')]
#[Description('Take screenshots of blocks for the visual editor\'s block list, each from the block\'s example data, and save them as if uploaded in Settings › Blocks. By default only the blocks with no picture yet; pass "type" for one block, which replaces its picture.')]
class GenerateBlockScreenshots extends CmsTool
{
    protected bool $needsSite = false;

    /**
     * Only where Aurora can take screenshots (spatie/browsershot installed), for people who
     * may upload them in Settings › Blocks.
     */
    public function shouldRegister(Request $request): bool
    {
        return BlockScreenshots::canCapture() && (bool) $this->actingUser($request)?->can('manage-settings');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Optional: one block type, e.g. "hero". Its picture is replaced.'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        if (filled($type = $request->get('type')) && Blocks::find($type) === null) {
            return Response::error("Unknown block type \"{$type}\". list_block_types lists them.");
        }

        $types = filled($type) ? [$type] : BlockScreenshots::missing();
        $saved = [];
        $failed = [];

        foreach ($types as $each) {
            try {
                BlockScreenshots::saveAsUploaded($each, BlockScreenshots::capture($each));
                $saved[] = $each;
            } catch (Throwable $e) {
                $failed[$each] = $e->getMessage();
            }
        }

        $this->did($saved === [] ? 'Took no block screenshots' : 'Took screenshots of '.count($saved).' '.str('block')->plural(count($saved)).': '.implode(', ', $saved));

        return Response::json(array_filter([
            'saved' => $saved,
            'failed' => $failed ?: null,
            'note' => $types === [] ? 'Every block already has a picture.' : null,
        ], fn (mixed $value): bool => $value !== null));
    }
}
