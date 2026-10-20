<?php

namespace Aurora\Http\Controllers\Api;

use Aurora\Http\Resources\MenuItemResource;
use Aurora\Models\Menu;
use Aurora\Support\ApiParameter;
use Illuminate\Http\JsonResponse;

/**
 * GET /menus/{location}: the menu shown in one of the website's menu locations ("header").
 */
#[ApiParameter('location', 'A menu location\'s handle, such as "header" or "footer".', in: 'path', example: 'header')]
class ShowMenuController
{
    public function __invoke(string $location): JsonResponse
    {
        $menu = Menu::location($location) ?? abort(404);

        return response()->json(['data' => [
            'name' => $menu->name,
            'location' => $location,
            'items' => MenuItemResource::collection($menu->menuItems),
        ]]);
    }
}
