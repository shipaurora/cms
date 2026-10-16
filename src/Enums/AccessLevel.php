<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What someone may do with a file or folder in the media library. Write includes read.
 */
enum AccessLevel: string implements HasIcon, HasLabel
{
    case Read = 'read';
    case Write = 'write';

    public function getLabel(): string
    {
        return match ($this) {
            // Its own key: "Read" alone is a form submission that has been read.
            self::Read => __('Read (access)'),
            self::Write => __('Read & write'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Read => 'phosphor-eye',
            self::Write => 'phosphor-pencil-simple',
        };
    }

    public function includes(AccessLevel $level): bool
    {
        return $this === self::Write || $level === self::Read;
    }
}
