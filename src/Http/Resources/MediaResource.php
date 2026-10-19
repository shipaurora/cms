<?php

namespace Aurora\Http\Resources;

use Aurora\Models\Asset;
use Aurora\Support\ApiContent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file in the REST API: its addresses, sizes, alt text and focal point
 * (Aurora\Support\ApiContent::asset()), and nothing about where it's kept or who added it.
 *
 * @mixin Asset
 */
class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ApiContent::asset($this->resource);
    }
}
