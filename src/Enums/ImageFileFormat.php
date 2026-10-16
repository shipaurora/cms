<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The kind of file an image format is saved as.
 */
enum ImageFileFormat: string implements HasLabel
{
    case Webp = 'webp';
    case Avif = 'avif';
    case Jpeg = 'jpg';
    case Png = 'png';

    public function getLabel(): string
    {
        return match ($this) {
            self::Webp => 'WebP',
            self::Avif => 'AVIF',
            self::Jpeg => 'JPEG',
            self::Png => 'PNG',
        };
    }
}
