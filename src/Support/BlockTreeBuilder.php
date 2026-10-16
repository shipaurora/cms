<?php

namespace Aurora\Support;

use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Converts between the page builder's flat block list and a nested tree.
 *
 * Storage is flat (id, parent, slot, position) because that is what makes drag, drop
 * and undo cheap. People and AI assistants think in trees, so the seeder and the MCP
 * server read and write this shape:
 *
 *     ['type' => 'band', 'data' => [...], 'children' => ['content' => [ ...blocks ]]]
 *
 * A container with a single slot may list its children directly: `'children' => [...]`.
 */
class BlockTreeBuilder
{
    public const MAX_DEPTH = BlockTree::MAX_DEPTH;

    /**
     * @param  array<int, array<string, mixed>>  $tree
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $tree): array
    {
        $flat = [];
        static::walk(array_values($tree), null, null, 1, $flat);

        return $flat;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function nest(mixed $blocks): array
    {
        $blocks = BlockTree::hydrate($blocks);

        return static::nestChildren($blocks, null, null);
    }

    /**
     * Build one flat block (and its descendants) to splice into an existing page.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, array<string, mixed>>
     */
    public static function flattenNode(array $node, ?string $parent, ?string $slot, int $position, int $depth = 1): array
    {
        $flat = [];
        static::walk([$node], $parent, $slot, $depth, $flat, $position);

        return $flat;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, array<string, mixed>>  $flat
     */
    protected static function walk(array $nodes, ?string $parent, ?string $slot, int $depth, array &$flat, int $offset = 0): void
    {
        if ($depth > static::MAX_DEPTH) {
            throw new InvalidArgumentException('Blocks may be nested at most '.static::MAX_DEPTH.' levels deep.');
        }

        foreach (array_values($nodes) as $position => $node) {
            if (! is_array($node) || ! is_string($node['type'] ?? null)) {
                throw new InvalidArgumentException('Every block needs a "type".');
            }

            $type = $node['type'];

            if (! static::registry()->has($type)) {
                throw new InvalidArgumentException("Unknown block type \"{$type}\". Use list_block_types to see the available types.");
            }

            $id = is_string($node['id'] ?? null) && $node['id'] !== '' ? $node['id'] : (string) Str::uuid();
            $data = array_replace(static::registry()->defaults($type), is_array($node['data'] ?? null) ? $node['data'] : []);

            $block = [
                'id' => $id,
                'type' => $type,
                'data' => $data,
                'parent' => $parent,
                'slot' => $slot,
                'position' => $offset + $position,
                'settings' => is_array($node['settings'] ?? null) ? $node['settings'] : [],
            ];

            if (is_array($node['style'] ?? null) && $node['style'] !== []) {
                $block['style'] = $node['style'];
            }

            if (is_string($node['anchor'] ?? null) && BlockTree::isValidAnchor($node['anchor'])) {
                $block['anchor'] = $node['anchor'];
            }

            $flat[] = $block;

            $children = $node['children'] ?? [];

            if ($children === [] || $children === null) {
                continue;
            }

            $slots = static::registry()->slots($type, $data);

            if ($slots === []) {
                throw new InvalidArgumentException("A \"{$type}\" block cannot contain other blocks.");
            }

            // A plain list goes into the first slot.
            if (array_is_list($children)) {
                $children = [$slots[0] => $children];
            }

            foreach ($children as $slotName => $slotChildren) {
                if (! in_array($slotName, $slots, true)) {
                    throw new InvalidArgumentException("A \"{$type}\" block has no slot \"{$slotName}\". Its slots are: ".implode(', ', $slots).'.');
                }

                static::walk((array) $slotChildren, $id, $slotName, $depth + 1, $flat);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    protected static function nestChildren(array $blocks, ?string $parent, ?string $slot): array
    {
        return array_map(function (array $block) use ($blocks): array {
            $node = [
                'id' => $block['id'],
                'type' => $block['type'],
                'data' => $block['data'] ?? [],
            ];

            foreach (['style', 'settings', 'anchor'] as $key) {
                if (! empty($block[$key])) {
                    $node[$key] = $block[$key];
                }
            }

            $slots = static::registry()->slots($block['type'], $block['data'] ?? []);

            if ($slots !== []) {
                $node['children'] = [];

                foreach ($slots as $name) {
                    $node['children'][$name] = static::nestChildren($blocks, $block['id'], $name);
                }
            }

            return $node;
        }, BlockTree::childrenOf($blocks, $parent, $slot));
    }

    protected static function registry(): BlockRegistry
    {
        return app(BlockRegistries::class)->for('admin');
    }
}
