<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\SearchResultResource;
use Aurora\Models\Page;
use Aurora\Support\ApiParameter;
use Aurora\Support\PageSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /search?q=…: the site's pages a search finds, as the website's own search finds them
 * (live, anyone may see them, not left out of search), most relevant first, 20 to a page.
 */
#[ApiParameter('q', 'What to search for.', required: true, example: 'pricing')]
#[ApiParameter('page', 'Which page of results, 20 to a page.', type: 'integer', example: 2)]
class SearchController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']]);

        $terms = $request->string('q')->toString();
        $pages = Page::search($terms)->paginate(20);

        // How well each matched, for the results to say.
        $request->attributes->set(SearchResultResource::SCORES, PageSearch::scores(array_map(fn (Page $page): string => $page->getKey(), $pages->items()), $terms));

        return SearchResultResource::collection($pages);
    }
}
