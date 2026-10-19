<?php

namespace Aurora\Http\Controllers;

use Aurora\Support\CanvasStyles;
use Illuminate\Http\Response;

/**
 * The website's stylesheet for the visual editor's canvas, scoped to it (CanvasStyles). Its
 * address changes with every build, so it's kept for good; from the Vite dev server, never.
 */
class CanvasStylesController
{
    public function __invoke(): Response
    {
        $css = CanvasStyles::css();

        abort_if($css === null, 404);

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => CanvasStyles::isHot() ? 'no-cache' : 'private, max-age=31536000, immutable',
        ]);
    }
}
