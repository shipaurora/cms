<?php

namespace Aurora\Http\Controllers;

use Aurora\Support\Themes;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A theme's picture, for choosing it under Settings › Appearance: straight from the package
 * or site that registered it, nothing to publish.
 */
class ThemePreviewController
{
    public function __invoke(string $theme): BinaryFileResponse
    {
        Gate::authorize('manage-settings');

        $file = Themes::all()[$theme]['preview'] ?? null;

        abort_unless(is_string($file) && is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=86400']);
    }
}
