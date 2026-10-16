<?php

namespace Aurora\Database\Seeders\Demo\Support;

/**
 * A terse way to write page block trees in seeders. Each method returns a nested block
 * node that BlockTreeBuilder::flatten() turns into the stored flat list.
 */
class B
{
    public static function band(array $children, string $background = 'white', string $padding = 'lg', string $width = 'default', array $extra = []): array
    {
        return ['type' => 'band', 'data' => ['background' => $background, 'padding' => $padding, 'width' => $width, ...$extra], 'children' => $children];
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $columns
     */
    public static function columns(array $columns, ?string $ratio = null, string $gap = 'xl', string $valign = 'center', string $stack = 'mobile'): array
    {
        $count = count($columns);
        $children = [];

        foreach (array_values($columns) as $index => $blocks) {
            $children['col-'.$index] = $blocks;
        }

        return [
            'type' => 'section',
            'data' => ['columns' => $count, 'ratio' => $ratio ?? implode('-', array_fill(0, $count, '1')), 'gap' => $gap, 'valign' => $valign, 'stack' => $stack],
            'children' => $children,
        ];
    }

    public static function grid(array $items, int $columns = 3, string $gap = 'md', string $align = 'stretch'): array
    {
        return ['type' => 'grid', 'data' => ['columns' => (string) $columns, 'gap' => $gap, 'align' => $align], 'children' => $items];
    }

    public static function buttons(array $buttons, string $justify = 'start'): array
    {
        return ['type' => 'button-group', 'data' => ['justify' => $justify], 'children' => $buttons];
    }

    public static function heading(string $text, ?string $eyebrow = null, ?string $intro = null, string $size = 'lg', string $align = 'left', string $level = 'h2'): array
    {
        return ['type' => 'heading', 'data' => array_filter(compact('text', 'eyebrow', 'intro', 'size', 'align', 'level'), fn ($value) => $value !== null)];
    }

    public static function text(string $body, string $size = 'base', string $align = 'left'): array
    {
        return ['type' => 'text', 'data' => compact('body', 'size', 'align')];
    }

    public static function rich(string $html, string $width = 'prose'): array
    {
        return ['type' => 'rich-text', 'data' => ['body' => $html, 'width' => $width]];
    }

    public static function button(string $label, ?string $page = null, string $variant = 'primary', ?string $url = null, string $size = 'md', ?string $icon = null): array
    {
        return ['type' => 'button', 'data' => array_filter([
            'label' => $label,
            'variant' => $variant,
            'size' => $size,
            'icon' => $icon !== null ? "hero-m-{$icon}" : null,
            'link_type' => $url ? 'url' : ($page ? 'page' : 'none'),
            'link_page' => $page,
            'link_url' => $url,
        ], fn ($value) => $value !== null)];
    }

    public static function feature(string $icon, string $title, string $body, string $style = 'soft', string $tone = 'brand'): array
    {
        $icon = "hero-o-{$icon}";

        return ['type' => 'feature', 'data' => compact('icon', 'title', 'body', 'style', 'tone')];
    }

    public static function stat(string $value, string $label, ?string $suffix = null, ?string $prefix = null, ?string $description = null): array
    {
        // An empty suffix rather than none: the block's default one ("%") would show otherwise.
        $suffix ??= '';

        return ['type' => 'stat', 'data' => array_filter(compact('value', 'label', 'suffix', 'prefix', 'description') + ['animate' => true], fn ($value) => $value !== null)];
    }

    public static function card(string $title, string $body, ?string $image = null, ?string $badge = null, ?string $page = null, string $style = 'elevated', string $linkLabel = 'Read more'): array
    {
        return ['type' => 'card', 'data' => array_filter([
            'title' => $title,
            'body' => $body,
            'image' => $image,
            'badge' => $badge,
            'style' => $style,
            'link_label' => $linkLabel,
            'link_type' => $page ? 'page' : 'none',
            'link_page' => $page,
        ], fn ($value) => $value !== null)];
    }

    public static function testimonial(string $quote, string $name, string $role, ?string $avatar = null, string $style = 'card'): array
    {
        return ['type' => 'testimonial', 'data' => array_filter(compact('quote', 'name', 'role', 'avatar', 'style') + ['rating' => '5'], fn ($value) => $value !== null)];
    }

    public static function image(string $src, string $alt, ?string $caption = null, string $ratio = 'auto', bool $shadow = false): array
    {
        return ['type' => 'image', 'data' => array_filter(compact('src', 'alt', 'caption', 'ratio', 'shadow') + ['rounded' => true], fn ($value) => $value !== null)];
    }

    public static function code(string $code, string $language = 'php', ?string $filename = null, ?string $caption = null): array
    {
        // Empty rather than missing: the block's defaults would fill them in otherwise.
        $filename ??= '';
        $caption ??= '';

        return ['type' => 'code-sample', 'data' => compact('code', 'language', 'filename', 'caption')];
    }

    public static function spacer(string $height = 'md'): array
    {
        return ['type' => 'spacer', 'data' => ['height' => $height]];
    }

    public static function make(string $type, array $data = [], array $children = []): array
    {
        return array_filter(['type' => $type, 'data' => $data, 'children' => $children]);
    }
}
