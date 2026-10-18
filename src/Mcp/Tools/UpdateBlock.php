<?php

namespace Aurora\Mcp\Tools;

use Aurora\Mcp\BlockInput;
use Aurora\Models\User;
use Aurora\Support\AssistantFiles;
use Aurora\Support\BlockCatalogue;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\Support\BlockStyle;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_block')]
#[Description('Change one block\'s content or look. "data" is merged into the existing data (only the fields you pass change) unless replace is true. Also sets brand presets ("settings"), exact styles ("style") and an "anchor".')]
class UpdateBlock extends CmsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->description('Page id or slug.')->required(),
            'block_id' => $schema->string()->description('The block id from get_page.')->required(),
            'data' => $schema->object()->description('Fields to change, per list_block_types.'),
            'replace' => $schema->boolean()->description('Replace all data instead of merging.'),
            'settings' => $schema->object()->description('Brand presets, e.g. {"padding": "lg", "background": "muted"}. Use "" to clear one.'),
            'style' => $schema->object()->description('Exact styles, e.g. {"text_align": "center"}.'),
            'anchor' => $schema->string()->description('In-page link id, e.g. "pricing".'),
        ];
    }

    protected function run(Request $request, User $user): Response
    {
        $page = $this->findPage($request);
        $blocks = BlockTree::hydrate($page->blocks ?? []);
        $index = BlockTree::indexOf($blocks, (string) $request->get('block_id'));

        if ($index === null) {
            throw new InvalidArgumentException('No block with that id on this page. Use get_page to see block ids.');
        }

        $block = $blocks[$index];

        if (! app(BlockRegistries::class)->for('admin')->isVisible($block['type'])) {
            return Response::error("You are not allowed to edit \"{$block['type']}\" blocks.");
        }

        if (($data = BlockInput::decode($request->get('data'))) !== null) {
            if (! is_array($data)) {
                throw new InvalidArgumentException('"data" must be an object.');
            }

            $created = [];

            try {
                $claimed = AssistantFiles::claimWithin($data, $created);
            } catch (InvalidArgumentException $exception) {
                AssistantFiles::discard($created);

                throw $exception;
            }

            if (! is_array($claimed)) {
                AssistantFiles::discard($created);

                throw new InvalidArgumentException('"data" must be an object.');
            }

            $data = $claimed;

            if ($errors = BlockCatalogue::validate($block['type'], $data)) {
                AssistantFiles::discard($created);

                throw new InvalidArgumentException("The block was not changed:\n- ".implode("\n- ", $errors));
            }

            $block['data'] = $request->get('replace') ? $data : array_replace($block['data'] ?? [], $data);
        }

        if (is_array($settings = BlockInput::decode($request->get('settings')))) {
            foreach ($settings as $token => $value) {
                if (is_string($token) && preg_match('/^[a-z-]+$/', $token)) {
                    if (blank($value)) {
                        unset($block['settings'][$token]);
                    } else {
                        $block['settings'][$token] = (string) $value;
                    }
                }
            }
        }

        if (is_array($style = BlockInput::decode($request->get('style')))) {
            $block['style'] = BlockStyle::sanitize([...($block['style'] ?? []), ...$style]);
        }

        if ($request->get('anchor') !== null) {
            $anchor = (string) $request->get('anchor');
            $block['anchor'] = BlockTree::isValidAnchor($anchor) ? $anchor : throw new InvalidArgumentException('An anchor uses lowercase letters, digits and dashes, e.g. "pricing".');
        }

        $blocks[$index] = $block;
        $page = $this->savePage($page, ['blocks' => $blocks], 'Edited a '.$block['type'].' block');

        return Response::json([
            'message' => 'Block updated.',
            'block' => ['id' => $block['id'], 'type' => $block['type'], 'data' => $block['data'], 'settings' => $block['settings'] ?? [], 'style' => $block['style'] ?? []],
            'preview' => $page->previewUrl(120),
        ]);
    }
}
