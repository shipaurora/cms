<?php

use Aurora\Blocks\Blocks;
use Aurora\Blocks\Fields;
use Aurora\Support\Markup;
use Illuminate\Support\HtmlString;

/*
 * Short forms of what block views use most, so they read without @php imports. Each is the
 * static method it names (Aurora\Support\Markup, Aurora\Blocks\Fields, Aurora\Blocks\Blocks),
 * which views can still call directly, and most are Blade directives too (AuroraServiceProvider). Named for what they read, never bare words like href()
 * or text(), so they can't clash with Laravel's helpers or other packages'.
 */

if (! function_exists('link_href')) {
    /**
     * The address a link field group points at (Fields::href()), or null when it points nowhere.
     *
     * @param  array<string, mixed>  $data
     */
    function link_href(array $data, string $prefix = 'link'): ?string
    {
        return Fields::href($data, $prefix);
    }
}

if (! function_exists('link_target')) {
    /**
     * "_blank" when a link field group opens in a new tab (Fields::target()).
     *
     * @param  array<string, mixed>  $data
     */
    function link_target(array $data, string $prefix = 'link'): ?string
    {
        return Fields::target($data, $prefix);
    }
}

if (! function_exists('inline_text')) {
    /**
     * Text with *highlighted* words (Markup::inline()).
     */
    function inline_text(mixed $text): HtmlString
    {
        return Markup::inline($text);
    }
}

if (! function_exists('plain_text')) {
    /**
     * Plain text, escaped (Markup::text()).
     */
    function plain_text(mixed $text): HtmlString
    {
        return Markup::text($text);
    }
}

if (! function_exists('rich_text')) {
    /**
     * Rich text from the editor, cleaned (Markup::rich()).
     */
    function rich_text(mixed $html): HtmlString
    {
        return Markup::rich($html);
    }
}

if (! function_exists('shows_field')) {
    /**
     * Whether to render an optional field: filled, or on the canvas so it can be filled
     * (Markup::shows()).
     */
    function shows_field(string $field, mixed $value): bool
    {
        return Markup::shows($field, $value);
    }
}

if (! function_exists('is_editing')) {
    /**
     * Whether blocks are drawn on the visual editor's canvas (Blocks::isEditing()).
     */
    function is_editing(): bool
    {
        return Blocks::isEditing();
    }
}

if (! function_exists('block_slot')) {
    /**
     * A container block's slot, with the blocks in it (Blocks::slot()), ready to echo.
     */
    function block_slot(string $name): HtmlString
    {
        return new HtmlString(Blocks::slot($name));
    }
}
