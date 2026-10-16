<?php

namespace Aurora\Http\Controllers;

use Aurora\Aurora;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Aurora's own mark (the admin's logo and favicon, the mails' header), served from the
 * package so a site needs nothing published for it.
 */
class BrandImageController
{
    public const FILES = ['brand-mark.svg' => 'image/svg+xml', 'brand-mark.png' => 'image/png'];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(array_key_exists($file, self::FILES), 404);

        return response()->file(Aurora::path("resources/images/{$file}"), [
            'Content-Type' => self::FILES[$file],
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
