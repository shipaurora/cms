<?php

namespace Aurora\Filament\Pages;

use Aurora\Filament\Concerns\TranslatesPageLabels;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    use TranslatesPageLabels;

    /**
     * No page heading: the welcome card at the top already says where you are.
     * The browser tab keeps the "Dashboard" title.
     */
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
