<?php

namespace Aurora\Http\Controllers;

use Aurora\Support\Themes;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A theme's picture, for choosing it under Settings › Appearance: straight from the package
 * or site that registered it, nothing to publish. In light or dark mode, for a theme that has
 * a picture of each.
 */
class ThemePreviewController
{
    public function __invoke(string $theme, ?string $mode = null): BinaryFileResponse
    {
        Gate::authorize('manage-settings');

        $previews = Themes::all()[$theme]['previews'] ?? [];
        $file = $previews[$mode] ?? reset($previews) ?: null;

        abort_unless(is_string($file) && is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'private, max-age=86400']);
    }
}
