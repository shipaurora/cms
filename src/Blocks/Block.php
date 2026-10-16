<?php

namespace Aurora\Blocks;

use CarlJanzell\FilamentPageBuilder\Contracts\InlineEditable;
use CarlJanzell\FilamentPageBuilder\Contracts\PageBlock;
use CarlJanzell\FilamentPageBuilder\Editable;

/**
 * The shared shape of every block this site ships.
 *
 * A block is a class (its fields, defaults and what the AI should know about it) and an
 * anonymous Blade component at `components/blocks/{type}.blade.php` (in the app's views, or
 * a block package's anonymous component path). The same component
 * renders on the public site, inside the visual editor and in the preview, so what an
 * editor sees on the canvas is the real thing.
 */
abstract class Block implements InlineEditable, PageBlock
{
    abstract public static function type(): string;

    abstract public static function label(): string;

    abstract public static function description(): string;

    abstract public static function schema(): array;

    public static function icon(): ?string
    {
        return 'phosphor-stack';
    }

    /**
     * A picture of the block (a screenshot of it on a page), shown beside it in the visual
     * editor's block list so editors see what they're adding: any image URL, such as
     * asset('vendor/my-blocks/hero.png') or Vite::asset('resources/images/blocks/hero.png').
     * None: the list shows its icon and description only.
     */
    public static function preview(): ?string
    {
        return null;
    }

    /**
     * Palette group: layout, content, marketing, media, design or developer.
     */
    public static function category(): string
    {
        return 'content';
    }

    public static function view(): string
    {
        return 'blocks.'.static::type();
    }

    public static function fileFields(): array
    {
        return [];
    }

    /**
     * Text fields that are the same in every language (a file name, a product code): never
     * offered for translation, nor counted as left to translate.
     *
     * @return array<int, string>
     */
    public static function sameInEveryLanguage(): array
    {
        return [];
    }

    public static function isVisible(): bool
    {
        return true;
    }

    /**
     * Data a freshly dropped block starts with. Good defaults make a block look finished
     * the moment it lands on the canvas, rather than an empty box to configure.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [];
    }

    /**
     * @return array<string, Editable>
     */
    public static function editables(): array
    {
        return [];
    }

    /**
     * Fields inside repeater items that may be edited in place, keyed by repeater field.
     *
     * `['items' => ['question' => Editable::text()]]` lets an editor click a question of
     * the third accordion item and type, which writes `items.2.question`.
     *
     * @return array<string, array<string, Editable>>
     */
    public static function itemEditables(): array
    {
        return [];
    }

    /**
     * Extra guidance for AI assistants composing pages over MCP: when to use the block,
     * what good content looks like, how it combines with others.
     */
    public static function aiHints(): ?string
    {
        return null;
    }
}
