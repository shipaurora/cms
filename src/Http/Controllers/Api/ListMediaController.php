<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Enums\AssetType;
use Aurora\Http\Resources\MediaResource;
use Aurora\Models\Asset;
use Aurora\Support\ApiContent;
use Aurora\Support\ApiParameter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * GET /media: the files the website's visible content uses (on pages anyone may see, and in
 * the public settings), newest first, 50 to a page. ?type=image for one kind. The rest of the
 * media library isn't listed.
 */
#[ApiParameter('type', 'Only one kind of file.', enum: AssetType::class)]
#[ApiParameter('page', 'Which page of results, 50 to a page.', type: 'integer', example: 2)]
class ListMediaController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate(['type' => ['nullable', Rule::enum(AssetType::class)]]);

        return MediaResource::collection(Asset::query()
            ->whereIn('id', ApiContent::publicAssetIds())
            ->when($request->query('type'), fn ($query, string $type) => AssetType::from($type)->scope($query))
            ->latest()
            ->paginate(50));
    }
}
