<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SubmissionStatus: string implements HasColor, HasIcon, HasLabel
{
    case New = 'new';
    case Read = 'read';
    case Archived = 'archived';
    case Spam = 'spam';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('New'),
            self::Read => __('Read'),
            self::Archived => __('Archived'),
            self::Spam => __('Spam'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'primary',
            self::Read => 'gray',
            self::Archived => 'gray',
            self::Spam => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::New => 'phosphor-envelope-simple',
            self::Read => 'phosphor-envelope-open',
            self::Archived => 'phosphor-archive',
            self::Spam => 'phosphor-shield-warning',
        };
    }
}
