<?php

namespace Aurora\Http\Controllers;

use Aurora\Models\Asset;
use Illuminate\Http\RedirectResponse;

/**
 * A media library file's lasting address (/files/{id}), for links to PDFs, price lists and
 * the like. It sends the visitor on to wherever the file is stored right now, so a link
 * keeps working when the file is replaced or the storage moves.
 */
class AssetFileController
{
    public function __invoke(Asset $asset): RedirectResponse
    {
        $url = $asset->url(null);

        abort_if($url === null, 404);

        return redirect()->away($url);
    }
}
