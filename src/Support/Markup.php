<?php

namespace Aurora\Support;

use CarlJanzell\FilamentPageBuilder\PageBuilder;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\HtmlString;

/**
 * Text helpers for block views.
 */
class Markup
{
    /**
     * Plain text with `*highlighted*` words painted in the brand gradient.
     *
     * While the canvas is editing, the asterisks stay in the markup (faintly) so the
     * contenteditable's innerText round-trips the exact source the editor typed.
     */
    public static function inline(mixed $text): HtmlString
    {
        $text = is_scalar($text) ? (string) $text : '';
        $editing = PageBuilder::isEditing();
        $html = $editing ? e($text) : PageBuilder::text($text);

        $html = preg_replace_callback('/\*([^*\n]+)\*/u', function (array $match) use ($editing): string {
            return $editing
                ? '<span class="hl-mark">*</span><span class="text-gradient">'.$match[1].'</span><span class="hl-mark">*</span>'
                : '<span class="text-gradient">'.$match[1].'</span>';
        }, $html) ?? $html;

        return new HtmlString($html);
    }

    /**
     * Plain text, escaped, line breaks preserved by the caller's `whitespace-pre-line`.
     */
    public static function text(mixed $text): HtmlString
    {
        return new HtmlString(PageBuilder::text($text));
    }

    /**
     * Stored rich text (HTML from the RichEditor, or written over MCP), sanitised.
     */
    public static function rich(mixed $html): HtmlString
    {
        if (! is_string($html) || trim($html) === '') {
            return new HtmlString('');
        }

        return new HtmlString(PageBuilder::markup(RichContentRenderer::make($html)->toHtml()));
    }

    /**
     * Whether a field should render: filled, or editable on the canvas so it can be filled.
     */
    public static function shows(string $field, mixed $value): bool
    {
        return PageBuilder::shows($field, $value);
    }
}
