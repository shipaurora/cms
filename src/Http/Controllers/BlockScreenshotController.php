<?php

namespace Aurora\Http\Controllers;

use Aurora\Blocks\Blocks;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A block's screenshot from the site's resources/block-previews, for the visual editor's
 * block list: straight from the site's code, nothing to publish. Only for people who may
 * edit pages. Its address changes with the file (Blocks::screenshotUrl()), so browsers keep
 * it for long.
 */
class BlockScreenshotController
{
    public function __invoke(string $type): BinaryFileResponse
    {
        Gate::authorize('update Page');

        $file = Blocks::screenshotFile($type);

        abort_if($file === null, 404);

        // A file response is public on its own: this one is for signed-in people only.
        return response()->file($file)->setPrivate()->setMaxAge(31536000)->setImmutable();
    }
}
