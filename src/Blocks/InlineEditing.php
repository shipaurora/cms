<?php

namespace Aurora\Blocks;

use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Editable;
use CarlJanzell\FilamentPageBuilder\PageBuilder;
use Illuminate\Support\Js;

/**
 * In-place editing for fields inside repeater items, alongside the package's `@editable`.
 *
 * `@editableItem('items', $loop->index, 'question')` emits the same attributes the canvas
 * script already understands, with a dotted field path. The design page accepts that path
 * only when the block declared the item field in `itemEditables()`.
 */
class InlineEditing extends PageBuilder
{
    public static function currentBlockType(): ?string
    {
        return static::$blockType;
    }

    public static function itemEditableFor(string $repeater, string $field): ?Editable
    {
        if (! static::isEditing()) {
            return null;
        }

        $block = app(BlockRegistry::class)->find(static::$blockType);

        if ($block === null || ! is_subclass_of($block, Block::class)) {
            return null;
        }

        $editable = $block::itemEditables()[$repeater][$field] ?? null;

        return $editable instanceof Editable ? $editable : null;
    }

    /**
     * Makes an empty image placeholder on the canvas open the media picker for that field:
     * the click selects the block, then mounts the field's "pickAsset" action in the
     * inspector. Emits nothing on the public site.
     */
    public static function pickerAttributes(string $field): string
    {
        if (! static::isEditing()) {
            return '';
        }

        $blockId = e(Js::from((string) static::$blockId));
        $component = e(Js::from('form.'.$field));

        $pick = '$wire.selectBlock('.$blockId.').then(() => $wire.mountAction(\'pickAsset\', {}, { schemaComponent: '.$component.' }))';

        // A button for the keyboard too: Enter and Space pick as a click does.
        return 'data-fpb-picker role="button" tabindex="0" title="'.e(__('Choose an image')).'" aria-label="'.e(__('Choose an image')).'"'
            .' x-on:click.stop="'.$pick.'" x-on:keydown.enter.stop.prevent="'.$pick.'" x-on:keydown.space.stop.prevent="'.$pick.'"';
    }

    public static function itemAttributes(string $repeater, int|string $index, string $field): string
    {
        $editable = static::itemEditableFor($repeater, $field);

        if ($editable === null) {
            return '';
        }

        $attributes = [
            'data-fpb-block="'.e((string) static::$blockId).'"',
            'data-fpb-field="'.e("{$repeater}.{$index}.{$field}").'"',
            'data-fpb-kind="'.e($editable->kind).'"',
        ];

        // Rich text gets its editor when clicked (resources/js/admin/canvas-rich-text.js).
        if ($editable->kind === Editable::TEXT) {
            $attributes[] = 'contenteditable="plaintext-only"';
            $attributes[] = 'data-fpb-multiline="'.($editable->isMultiline() ? 'true' : 'false').'"';
        }

        if (filled($placeholder = $editable->getPlaceholder())) {
            $attributes[] = 'data-fpb-placeholder="'.e($placeholder).'"';
        }

        return implode(' ', $attributes);
    }
}
