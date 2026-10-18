<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\PageResource;
use Aurora\Models\Page;

/**
 * GET /pages/{id}: one live page anyone may see, with its blocks.
 */
class ShowPageController
{
    public function __invoke(string $page): PageResource
    {
        return new PageResource(Page::query()->public()->findOrFail($page));
    }
}
