<?php

namespace Workbench\App\Blocks\Concerns;

/**
 * The block's picture from the site's own screenshots (resources/block-previews/{type}.webp),
 * shown beside it in the visual editor's block list; none when there's no screenshot.
 */
trait HasPreviewImage
{
    public static function preview(): ?string
    {
        $file = resource_path('block-previews/'.static::type().'.webp');

        return is_file($file) ? route('aurora.blocks.preview', ['type' => static::type(), 'v' => filemtime($file)]) : null;
    }
}
