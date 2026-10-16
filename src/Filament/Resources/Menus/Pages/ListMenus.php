<?php

namespace Aurora\Filament\Resources\Menus\Pages;

use Aurora\Filament\Concerns\ManagesMenuLocations;
use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Datlechin\FilamentMenuBuilder\Resources\MenuResource\Pages\ListMenus as BaseListMenus;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;

/**
 * The menu builder's list, without "create another": a new menu opens for editing. Creating
 * is the primary action, so it gets the plus icon and the rightmost spot, as on other lists.
 */
class ListMenus extends BaseListMenus
{
    use ManagesMenuLocations;
    use WithoutBreadcrumbs;

    protected function getHeaderActions(): array
    {
        return collect(parent::getHeaderActions())
            ->map(fn ($action) => $action instanceof CreateAction ? $action->createAnother(false)->icon('phosphor-plus') : $action)
            // The locations themselves (their names, new ones) under "More", before New.
            ->push(ActionGroup::make([$this->menuLocationsAction()])
                ->label(__('More'))
                ->icon('phosphor-dots-three-vertical')
                ->button()
                ->color('gray'))
            ->sortBy(fn ($action): bool => $action instanceof CreateAction)
            ->values()
            ->all();
    }
}
