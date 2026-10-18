<?php

namespace Aurora\Http\Resources;

use Aurora\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A page in a list, in the content API.
 *
 * @mixin Page
 */
class PageSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => $this->path(),
            'url' => $this->url(),
            'excerpt' => $this->excerpt,
            'parent_id' => $this->parent_id,
            'is_homepage' => (bool) $this->is_homepage,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
