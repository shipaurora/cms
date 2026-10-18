<?php

namespace Aurora\Mcp;

use Aurora\Models\Asset;
use Aurora\Support\AssistantFiles;
use Aurora\Support\BlockCatalogue;
use CarlJanzell\FilamentPageBuilder\BlockRegistries;
use CarlJanzell\FilamentPageBuilder\Support\BlockStyle;
use InvalidArgumentException;

/**
 * Validates blocks an assistant sends before they reach a page.
 *
 * The canvas refuses unknown fields and blocks the user may not author; so does this.
 * Errors name the field and list what would have been accepted, which is usually
 * enough for the model to correct itself on the next call.
 */
class BlockInput
{
    /**
     * Accept a value that should be a JSON object/array but may arrive as a JSON string.
     */
    public static function decode(mixed $value): mixed
    {
        if (is_string($value) && in_array(ltrim($value)[0] ?? '', ['{', '['], true)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('Could not parse JSON: '.json_last_error_msg());
            }

            return $decoded;
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, array<string, mixed>>
     */
    public static function tree(array $nodes, string $path = 'blocks'): array
    {
        $created = [];

        try {
            $nodes = static::claimNodes(array_values($nodes), $created);
        } catch (InvalidArgumentException $exception) {
            AssistantFiles::discard($created);

            throw $exception;
        }

        $errors = [];

        foreach ($nodes as $index => $node) {
            array_push($errors, ...static::errorsFor($node, "{$path}[{$index}]"));
        }

        if ($errors !== []) {
            AssistantFiles::discard($created);

            throw new InvalidArgumentException("The blocks were not saved:\n- ".implode("\n- ", array_slice($errors, 0, 15)));
        }

        return array_map(fn ($node) => static::clean($node), $nodes);
    }

    /**
     * Swap attached-file ids in block data for media library ids, before validation.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, Asset>  $created
     * @return array<int, mixed>
     */
    protected static function claimNodes(array $nodes, array &$created): array
    {
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (array_key_exists('data', $node)) {
                $data = static::decode($node['data']);
                $node['data'] = is_array($data) ? AssistantFiles::claimWithin($data, $created) : $data;
            }

            if (array_key_exists('children', $node)) {
                $children = static::decode($node['children']);

                if (is_array($children) && $children !== []) {
                    $node['children'] = array_is_list($children)
                        ? static::claimNodes($children, $created)
                        : array_map(
                            fn (mixed $list): mixed => is_array($list) ? static::claimNodes(array_values($list), $created) : $list,
                            $children,
                        );
                }
            }

            $nodes[$index] = $node;
        }

        return $nodes;
    }

    /**
     * @return array<int, string>
     */
    protected static function errorsFor(mixed $node, string $path): array
    {
        if (! is_array($node) || ! is_string($node['type'] ?? null)) {
            return ["{$path}: every block needs a \"type\"."];
        }

        $type = $node['type'];
        $registry = app(BlockRegistries::class)->for('admin');

        if (! $registry->has($type)) {
            return ["{$path}: unknown block type \"{$type}\". Use list_block_types."];
        }

        if (! $registry->isVisible($type)) {
            return ["{$path}: you are not allowed to use \"{$type}\" blocks."];
        }

        $data = static::decode($node['data'] ?? []);

        if (! is_array($data)) {
            return ["{$path}.data must be an object."];
        }

        $errors = array_map(fn (string $error) => "{$path}: {$error}", BlockCatalogue::validate($type, $data));

        $children = static::decode($node['children'] ?? []);

        if (is_array($children)) {
            $lists = array_is_list($children) ? ['' => $children] : $children;

            foreach ($lists as $slot => $list) {
                foreach (array_values((array) $list) as $index => $child) {
                    array_push($errors, ...static::errorsFor($child, $path.'.children'.($slot !== '' ? ".{$slot}" : '')."[{$index}]"));
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    protected static function clean(array $node): array
    {
        $node['data'] = static::decode($node['data'] ?? []) ?: [];

        if (isset($node['style'])) {
            $node['style'] = BlockStyle::sanitize(static::decode($node['style']));
        }

        if (isset($node['settings'])) {
            $node['settings'] = array_filter((array) static::decode($node['settings']), fn ($value, $key) => is_string($key) && preg_match('/^[a-z-]+$/', $key) && is_string($value), ARRAY_FILTER_USE_BOTH);
        }

        $children = static::decode($node['children'] ?? []);

        if (is_array($children) && $children !== []) {
            $node['children'] = array_is_list($children)
                ? array_map(fn ($child) => static::clean($child), $children)
                : array_map(fn ($list) => array_map(fn ($child) => static::clean($child), array_values((array) $list)), $children);
        }

        return $node;
    }
}
