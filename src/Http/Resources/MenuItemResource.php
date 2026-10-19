<?php

namespace Aurora\Http\Resources;

use Aurora\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A menu item and the items under it, in the REST API.
 *
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'target' => $this->target?->value ?? $this->target,
            'children' => static::collection($this->children),
        ];
    }
}
