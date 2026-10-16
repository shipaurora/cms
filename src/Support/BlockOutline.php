<?php

namespace Aurora\Support;

use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Support\BlockTree;
use Illuminate\Support\Str;

/**
 * A page's blocks as a readable tree: what each block is and a hint of what it says.
 *
 * Used by the page form's content overview and by the MCP server, so a person and an
 * AI assistant look at the same picture of a page.
 */
class BlockOutline
{
    protected const SUMMARY_FIELDS = ['headline', 'text', 'title', 'heading', 'name', 'label', 'quote', 'body', 'question', 'value', 'caption', 'alt'];

    /**
     * @return array<int, array{id: string, type: string, label: string, icon: ?string, summary: ?string, slot: ?string, children: array<int, mixed>}>
     */
    public static function for(mixed $blocks): array
    {
        $blocks = BlockTree::hydrate($blocks);

        return static::children($blocks, null, null);
    }

    public static function count(mixed $blocks): int
    {
        return count(BlockTree::hydrate($blocks));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    protected static function children(array $blocks, ?string $parent, ?string $slot): array
    {
        return array_map(function (array $block) use ($blocks): array {
            $definition = static::registry()->find($block['type']);
            $slots = static::registry()->slots($block['type'], $block['data'] ?? []);

            $children = [];
            foreach ($slots as $name) {
                foreach (static::children($blocks, $block['id'], $name) as $child) {
                    $children[] = $child;
                }
            }

            return [
                'id' => $block['id'],
                'type' => $block['type'],
                'label' => $definition ? $definition::label() : Str::headline($block['type']),
                'icon' => $definition ? $definition::icon() : 'phosphor-question',
                'summary' => static::summary($block['data'] ?? []),
                'slot' => $block['slot'] ?? null,
                'children' => $children,
            ];
        }, BlockTree::childrenOf($blocks, $parent, $slot));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function summary(array $data): ?string
    {
        foreach (self::SUMMARY_FIELDS as $field) {
            if (is_string($data[$field] ?? null) && trim($data[$field]) !== '') {
                return Str::limit(trim(strip_tags(str_replace('*', '', $data[$field]))), 70);
            }
        }

        foreach (['items', 'logos', 'features'] as $field) {
            if (is_array($data[$field] ?? null) && $data[$field] !== []) {
                return count($data[$field]).' items';
            }
        }

        return null;
    }

    protected static function registry(): BlockRegistry
    {
        return app(BlockRegistries::class)->for('admin');
    }
}
