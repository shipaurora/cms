<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Models\Setting;
use Aurora\Support\ContentApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * GET /settings: the website's settings anyone may see (its name, colours, header and footer).
 */
class ShowSettingsController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => Arr::only(Setting::forCurrentSite(), ContentApi::publicSettings())]);
    }
}
