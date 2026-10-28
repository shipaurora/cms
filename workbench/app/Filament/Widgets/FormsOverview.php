<?php

namespace Workbench\App\Filament\Widgets;

use Aurora\Models\Form;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A website's own dashboard widget, registered with AdminPanel::widgets() as a site built on
 * Aurora registers its own (or a package's). It has no getDashboardLabel(): the dashboard
 * names it after its class.
 */
class FormsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Forms', Form::query()->count()),
        ];
    }
}
