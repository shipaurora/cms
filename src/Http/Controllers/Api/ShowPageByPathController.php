<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\PageResource;
use Aurora\Models\Page;
use Aurora\Support\ContentLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GET /page?path=/docs/getting-started: the page at an address on the website, as a frontend
 * routing its own addresses asks for it. "/" is the homepage.
 */
class ShowPageByPathController
{
    public function __invoke(Request $request): PageResource
    {
        $path = trim((string) $request->query('path', '/'), '/');

        $page = $path === ''
            ? Page::query()->public()->homepage()->firstOrFail()
            : Page::query()->public()->whereSlug(Str::afterLast($path, '/'), ContentLocale::current())->firstOrFail();

        // The rest of the address must be where the page sits.
        abort_unless($page->path() === '/'.ltrim(ContentLocale::prefix().'/'.$path, '/') || $page->path() === '/'.$path, 404);

        return new PageResource($page);
    }
}
