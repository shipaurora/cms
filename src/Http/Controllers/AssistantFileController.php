<?php

namespace Aurora\Http\Controllers;

use Aurora\Support\AssistantFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A picture attached in the assistant's chat, before it is saved into the media library.
 * Only the person who attached it can see it, and only the preview we made ourselves.
 */
class AssistantFileController
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = AssistantFiles::previewPath($file, auth()->user());

        abort_if($path === null, 404);

        return response()->file($path, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
