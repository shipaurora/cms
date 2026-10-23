<?php

namespace Aurora\Support;

use Aurora\Blocks\Blocks;

/**
 * Translations of a page's blocks. A page has one block structure, the same in every
 * language; other languages keep only their text, by block id and field:
 *
 *     {"<block id>": {"headline": "…", "body": "<p>…</p>"}}
 *
 * Which fields are text comes from the block catalogue: text, multi-line text, rich text,
 * Markdown and lists. Everything else (images, choices, toggles, links to pages) is shared,
 * and text without a translation shows the page's own language's (Page::primaryLocale()).
 */
class BlockTranslations
{
    /**
     * @var array<string, array<int, string>>
     */
    protected static array $fields = [];

    public const TEXT_TYPES = ['text', 'text (multi-line)', 'html', 'markdown', 'list of objects', 'list of strings'];

    /**
     * The fields of a block type that are translated.
     *
     * @return array<int, string>
     */
    public static function fieldsOf(string $type): array
    {
        $block = Blocks::find($type);
        $same = $block !== null ? $block::sameInEveryLanguage() : [];

        return static::$fields[$type] ??= collect(BlockCatalogue::describe($type)['fields'] ?? [])
            ->filter(fn (array $field): bool => static::isText($field) && ! str_ends_with($field['name'], '_url') && ! in_array($field['name'], $same, true))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Whether a described field holds text: a list of items only when its items have some
     * (a list of chosen records' ids is the same in every language).
     *
     * @param  array<string, mixed>  $field
     */
    protected static function isText(array $field): bool
    {
        if (! in_array($field['type'] ?? null, self::TEXT_TYPES, true)) {
            return false;
        }

        return ($field['type'] !== 'list of objects') || collect($field['item_fields'] ?? [])->contains(fn (array $item): bool => static::isText($item));
    }

    /**
     * The blocks with a language's text put in.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<int, array<string, mixed>>
     */
    public static function apply(array $blocks, array $translations): array
    {
        if ($translations === []) {
            return $blocks;
        }

        return array_map(function (array $block) use ($translations): array {
            $text = array_intersect_key($translations[$block['id'] ?? ''] ?? [], array_flip(static::fieldsOf((string) ($block['type'] ?? ''))));

            if ($text !== []) {
                $block['data'] = array_replace($block['data'] ?? [], $text);
            }

            return $block;
        }, $blocks);
    }

    /**
     * A language's text from blocks edited in that language: every text field that differs
     * from the structure's own, and text the same in both that was confirmed as its
     * translation ($same: "<block id>.<field>", a name or a title that doesn't change).
     * Anything else that changed (blocks added, moved or restyled) is not kept: the structure
     * is shared and edited in the page's own language.
     *
     * @param  array<int, array<string, mixed>>  $structure
     * @param  array<int, array<string, mixed>>  $translated
     * @param  array<int, string>  $same
     * @return array<string, array<string, mixed>>
     */
    public static function extract(array $structure, array $translated, array $same = []): array
    {
        $original = collect($structure)->keyBy('id');
        $translations = [];

        foreach ($translated as $block) {
            $source = $original->get($block['id'] ?? '');

            if ($source === null) {
                continue;
            }

            foreach (static::fieldsOf((string) $source['type']) as $field) {
                $value = $block['data'][$field] ?? null;

                if (filled($value) && ($value !== ($source['data'][$field] ?? null) || in_array($source['id'].'.'.$field, $same, true))) {
                    $translations[$source['id']][$field] = $value;
                }
            }
        }

        return $translations;
    }

    /**
     * The translations that are the same as the page's own text: confirmed as such, they
     * stay translated ("<block id>.<field>", as extract() takes them).
     *
     * @param  array<int, array<string, mixed>>  $structure
     * @param  array<string, array<string, mixed>>  $translations
     * @return array<int, string>
     */
    public static function sameAsSource(array $structure, array $translations): array
    {
        $original = collect($structure)->keyBy('id');
        $same = [];

        foreach ($translations as $id => $fields) {
            foreach ((array) $fields as $field => $value) {
                if (filled($value) && $value === ($original->get($id)['data'][$field] ?? null)) {
                    $same[] = $id.'.'.$field;
                }
            }
        }

        return $same;
    }

    /**
     * How many text fields with content have no translation yet.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<string, array<string, mixed>>  $translations
     */
    public static function missing(array $blocks, array $translations): int
    {
        $missing = 0;

        foreach ($blocks as $block) {
            foreach (static::fieldsOf((string) ($block['type'] ?? '')) as $field) {
                if (filled($block['data'][$field] ?? null) && blank($translations[$block['id'] ?? ''][$field] ?? null)) {
                    $missing++;
                }
            }
        }

        return $missing;
    }
}
