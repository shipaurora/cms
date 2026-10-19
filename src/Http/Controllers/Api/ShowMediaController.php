<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\MediaResource;
use Aurora\Models\Asset;
use Aurora\Support\ApiContent;

/**
 * GET /media/{id}: one file the website's visible content uses, with its sizes. Any other
 * file in the media library answers 404, as if it weren't there.
 */
class ShowMediaController
{
    public function __invoke(string $asset): MediaResource
    {
        abort_unless(in_array($asset, ApiContent::publicAssetIds(), true), 404);

        return new MediaResource(Asset::query()->findOrFail($asset));
    }
}
