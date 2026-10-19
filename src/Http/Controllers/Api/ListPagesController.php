<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\PageSummaryResource;
use Aurora\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /pages: the site's live pages anyone may see, newest change first, 50 to a page.
 * ?parent={id} lists the pages under one, ?parent= the top level: an app's navigation.
 */
class ListPagesController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return PageSummaryResource::collection(Page::query()
            ->public()
            ->when($request->has('parent'), fn ($query) => $query->where('parent_id', $request->query('parent') ?: null))
            ->latest('updated_at')
            ->paginate(50));
    }
}
