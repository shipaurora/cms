<?php

namespace Aurora\Filament\Resources\Menus\Pages;

use Aurora\Filament\Concerns\WithoutBreadcrumbs;
use Datlechin\FilamentMenuBuilder\Resources\MenuResource\Pages\ListMenus as BaseListMenus;
use Filament\Actions\CreateAction;

/**
 * The menu builder's list, without "create another": a new menu opens for editing. Creating
 * is the primary action, so it gets the plus icon and the rightmost spot, as on other lists.
 */
class ListMenus extends BaseListMenus
{
    use WithoutBreadcrumbs;

    protected function getHeaderActions(): array
    {
        return collect(parent::getHeaderActions())
            ->map(fn ($action) => $action instanceof CreateAction ? $action->createAnother(false)->icon('phosphor-plus') : $action)
            ->sortBy(fn ($action): bool => $action instanceof CreateAction)
            ->values()
            ->all();
    }
}
