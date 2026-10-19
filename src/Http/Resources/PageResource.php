<?php

namespace Aurora\Http\Resources;

use Aurora\Models\Page;
use Aurora\Support\ApiContent;
use Aurora\Support\BlockTreeBuilder;
use Illuminate\Http\Request;

/**
 * A page with what it says, in the REST API: its blocks as a nested tree in the language
 * asked for, structured (Aurora\Support\ApiContent), its layout and its SEO.
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
                'image' => ApiContent::fieldAsset($this->og_image),
                'noindex' => (bool) $this->noindex,
            ],
            'blocks' => ApiContent::blocks(BlockTreeBuilder::nest($this->localizedBlocks())),
        ];
    }
}
