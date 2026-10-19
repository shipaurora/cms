<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\PageResource;
use Aurora\Support\PublicApi;
use Illuminate\Http\Request;

/**
 * GET /page?path=/docs/getting-started: the page at an address on the website, as a frontend
 * routing its own addresses asks for it. "/" is the homepage.
 */
class ShowPageByPathController
{
    public function __invoke(Request $request): PageResource
    {
        return new PageResource(PublicApi::pageAt((string) $request->query('path', '/')) ?? abort(404));
    }
}
