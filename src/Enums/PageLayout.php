<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PageLayout: string implements HasDescription, HasIcon, HasLabel
{
    case Default = 'default';
    case Landing = 'landing';
    case Minimal = 'minimal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Default => __('Standard'),
            self::Landing => __('Landing page'),
            self::Minimal => __('Minimal'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Default => 'phosphor-app-window',
            self::Landing => 'phosphor-sparkle',
            self::Minimal => 'phosphor-squares-four',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Default => __('Header, menu and footer.'),
            self::Landing => __('Transparent header floating over the first block.'),
            self::Minimal => __('Logo only, no menu or footer. Great for campaigns.'),
        };
    }
}
