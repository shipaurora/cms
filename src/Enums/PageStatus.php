<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PageStatus: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Review => __('In review'),
            self::Published => __('Published'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Review => 'warning',
            self::Published => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'phosphor-pencil-simple-line',
            self::Review => 'phosphor-hourglass-medium',
            self::Published => 'phosphor-globe-hemisphere-west',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Draft => __('Only visible to the team.'),
            self::Review => __('Ready for an editor to approve.'),
            self::Published => __('Live on the website (from the publish date).'),
        };
    }
}
