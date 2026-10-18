<?php

namespace Aurora\Http\Resources;

use Aurora\Models\Page;
use Aurora\Support\BlockTreeBuilder;
use Illuminate\Http\Request;

/**
 * A page with what it says, in the content API: its blocks as a nested tree in the language
 * asked for, its layout and its SEO.
 *
 * @mixin Page
 */
class PageResource extends PageSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'layout' => $this->layoutOrDefault()?->handle,
            'locales' => $this->availableLocales(),
            'seo' => [
                'title' => $this->seoTitle(),
                'description' => $this->meta_description ?: $this->excerpt,
                'image' => $this->og_image,
                'noindex' => (bool) $this->noindex,
            ],
            'blocks' => BlockTreeBuilder::nest($this->localizedBlocks()),
        ];
    }
}
