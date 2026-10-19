<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Support\ApiContent;
use Illuminate\Http\JsonResponse;

/**
 * GET /settings: the website's settings anyone may see: its name, tagline, colours and the
 * settings its blocks and layouts make public (a header's links, say). Nothing else.
 */
class ShowSettingsController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => ApiContent::settings()]);
    }
}
