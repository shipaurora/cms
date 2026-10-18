<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\PageSummaryResource;
use Aurora\Models\Page;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /pages: the site's live pages anyone may see, newest change first, 50 to a page.
 */
class ListPagesController
{
    public function __invoke(): AnonymousResourceCollection
    {
        return PageSummaryResource::collection(Page::query()->public()->latest('updated_at')->paginate(50));
    }
}
