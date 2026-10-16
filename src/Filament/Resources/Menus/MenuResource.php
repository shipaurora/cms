<?php

namespace Aurora\Filament\Resources\Menus;

use Aurora\Filament\Concerns\TranslatesResourceLabels;
use Aurora\Filament\Resources\Menus\Pages\EditMenu;
use Aurora\Filament\Resources\Menus\Pages\ListMenus;
use Datlechin\FilamentMenuBuilder\Resources\MenuResource as BaseMenuResource;

/**
 * The menu builder's resource, with our own list page (no "create another") and edit page
 * (items are added from an "Add" menu instead of the package's always-open side panels).
 */
class MenuResource extends BaseMenuResource
{
    use TranslatesResourceLabels;

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
