<?php

namespace Aurora\Http\Controllers;

use Aurora\Support\OpenApiSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The REST API's OpenAPI document, made from its routes as they are now (OpenApiSpec), for the
 * API explorer and for tools that generate a client from one.
 */
class OpenApiSpecController
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('manage-settings');

        return response()->json(OpenApiSpec::document(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
