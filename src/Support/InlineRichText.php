<?php

namespace Aurora\Support;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Throwable;
use Tiptap\Editor;
use Tiptap\Marks\Bold;
use Tiptap\Marks\Italic;
use Tiptap\Marks\Link;
use Tiptap\Marks\Strike;
use Tiptap\Marks\Underline;
use Tiptap\Nodes\Blockquote;
use Tiptap\Nodes\BulletList;
use Tiptap\Nodes\Document;
use Tiptap\Nodes\HardBreak;
use Tiptap\Nodes\Heading;
use Tiptap\Nodes\ListItem;
use Tiptap\Nodes\OrderedList;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Text;

/**
 * Rich text written right on the visual editor's canvas (Editable::richText()): formatting,
 * links, headings, lists and quotes, nothing else (no images, tables or code). The canvas
 * sends its editor's document as JSON; it's rebuilt here from what's allowed, never trusted,
 * and stored as HTML, as the inspector's rich editor stores it.
 *
 * Text holding anything more (a table from the inspector) stays the inspector's to edit:
 * the canvas's editor would drop what it doesn't know.
 */
class InlineRichText
{
    public const NODES = ['doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'hardBreak'];

    public const MARKS = ['bold', 'italic', 'underline', 'strike', 'link'];

    public const HEADING_LEVELS = [2, 3];

    /**
     * The most a document may weigh, in bytes of JSON: generous for prose.
     */
    protected const MAX_BYTES = 200_000;

    /**
     * Whether stored rich text can be edited on the canvas: empty, or nothing in it the
     * canvas's editor would lose.
     */
    public static function isEditable(mixed $html): bool
    {
        if (blank($html)) {
            return true;
        }

        $document = static::parse($html);

        return $document !== null && static::onlyAllowed($document);
    }

    /**
     * Stored rich text as the canvas's editor starts from: a document of allowed nodes, or
     * null when it isn't editable there.
     *
     * @return array<string, mixed>|null
     */
    public static function document(mixed $html): ?array
    {
        if (blank($html)) {
            return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
        }

        $document = static::parse($html);

        return $document !== null && static::onlyAllowed($document) ? static::clean($document) : null;
    }

    /**
     * A document from the canvas as HTML to store: rebuilt from the allowed nodes, marks and
     * attributes; null when it isn't a document at all. Empty text is an empty string.
     */
    public static function html(mixed $document): ?string
    {
        if (is_string($document)) {
            $document = strlen($document) <= static::MAX_BYTES ? json_decode($document, true) : null;
        }

        if (! is_array($document) || ($document['type'] ?? null) !== 'doc' || strlen((string) json_encode($document)) > static::MAX_BYTES) {
            return null;
        }

        $clean = static::clean($document);

        if (static::isEmpty($clean)) {
            return '';
        }

        return static::editor()->setContent($clean)->getHTML();
    }

    /**
     * The stored HTML as a document, read with everything the inspector's editor knows,
     * so that what the canvas's editor doesn't is seen rather than dropped.
     *
     * @return array<string, mixed>|null
     */
    protected static function parse(mixed $html): ?array
    {
        if (! is_string($html)) {
            return null;
        }

        try {
            $document = RichContentRenderer::make($html)->getEditor()->getDocument();
        } catch (Throwable) {
            return null;
        }

        return is_array($document) ? $document : null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected static function onlyAllowed(array $node): bool
    {
        if (! in_array($node['type'] ?? null, self::NODES, true)) {
            return false;
        }

        // Alignment set in the inspector would be lost too.
        if (filled($node['attrs']['textAlign'] ?? null) && $node['attrs']['textAlign'] !== 'start') {
            return false;
        }

        if (($node['type'] === 'heading') && ! in_array((int) ($node['attrs']['level'] ?? 0), self::HEADING_LEVELS, true)) {
            return false;
        }

        foreach ($node['marks'] ?? [] as $mark) {
            if (! in_array($mark['type'] ?? null, self::MARKS, true)) {
                return false;
            }
        }

        foreach ($node['content'] ?? [] as $child) {
            if (! is_array($child) || ! static::onlyAllowed($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The node rebuilt from what's allowed: other nodes and marks are left out (a list's
     * text is kept when the list can't be), and only the attributes each needs are kept.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    protected static function clean(array $node): ?array
    {
        $type = $node['type'] ?? null;

        if (! in_array($type, self::NODES, true)) {
            return null;
        }

        if ($type === 'text') {
            $text = $node['text'] ?? null;

            if (! is_string($text) || $text === '') {
                return null;
            }

            $marks = array_values(array_filter(array_map(fn (mixed $mark): ?array => is_array($mark) ? static::mark($mark) : null, (array) ($node['marks'] ?? []))));

            return array_filter(['type' => 'text', 'text' => $text, 'marks' => $marks ?: null]);
        }

        $clean = ['type' => $type];

        if ($type === 'heading') {
            $level = (int) ($node['attrs']['level'] ?? 0);
            $clean['attrs'] = ['level' => in_array($level, self::HEADING_LEVELS, true) ? $level : self::HEADING_LEVELS[0]];
        }

        if ($type === 'orderedList' && is_int($node['attrs']['start'] ?? null) && $node['attrs']['start'] > 1) {
            $clean['attrs'] = ['start' => $node['attrs']['start']];
        }

        $content = array_values(array_filter(array_map(fn (mixed $child): ?array => is_array($child) ? static::clean($child) : null, (array) ($node['content'] ?? []))));

        if ($content !== []) {
            $clean['content'] = $content;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $mark
     * @return array<string, mixed>|null
     */
    protected static function mark(array $mark): ?array
    {
        $type = $mark['type'] ?? null;

        if (! in_array($type, self::MARKS, true)) {
            return null;
        }

        if ($type !== 'link') {
            return ['type' => $type];
        }

        $href = trim((string) ($mark['attrs']['href'] ?? ''));

        if ($href === '' || ! (new Link)->isAllowedUri($href)) {
            return null;
        }

        // Opening in a new tab is the link's own choice; nothing else comes along.
        return ['type' => 'link', 'attrs' => array_filter([
            'href' => $href,
            'target' => ($mark['attrs']['target'] ?? null) === '_blank' ? '_blank' : null,
            'rel' => ($mark['attrs']['target'] ?? null) === '_blank' ? 'noopener' : null,
        ])];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected static function isEmpty(array $document): bool
    {
        return trim(static::text($document)) === '';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected static function text(array $node): string
    {
        return ($node['text'] ?? '').implode('', array_map(fn (array $child): string => static::text($child), $node['content'] ?? []));
    }

    /**
     * The canvas's set, and links as written: the package's defaults would open every
     * link in a new tab and mark it nofollow.
     */
    protected static function editor(): Editor
    {
        return new Editor(['extensions' => [
            new Document,
            new Paragraph,
            new Text,
            new Heading(['levels' => self::HEADING_LEVELS]),
            new BulletList,
            new OrderedList,
            new ListItem,
            new Blockquote,
            new HardBreak,
            new Bold,
            new Italic,
            new Underline,
            new Strike,
            new Link(['HTMLAttributes' => []]),
        ]]);
    }
}
