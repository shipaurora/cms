<?php

namespace Aurora\Http\Resources;

use Aurora\Models\Page;
use Aurora\Support\ApiContent;
use Illuminate\Http\Request;

/**
 * A page a search found, in the REST API: the page in brief, a few words around where it
 * matched, and how well it matched (when the search says).
 *
 * @mixin Page
 */
class SearchResultResource extends PageSummaryResource
{
    /**
     * The request attribute holding each result's score, by page id (SearchController).
     */
    public const SCORES = 'aurora.search-scores';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'snippet' => ApiContent::snippet($this->resource, (string) $request->query('q')),
            'score' => $request->attributes->get(self::SCORES)[$this->id] ?? null,
        ];
    }
}
