<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\ToggleButtons;

class GalleryBlock extends Block
{
    public static function type(): string
    {
        return 'gallery';
    }

    public static function label(): string
    {
        return __('Gallery');
    }

    public static function description(): string
    {
        return __('A grid of images that open in a lightbox.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-squares-four';
    }

    public static function category(): string
    {
        return 'media';
    }

    public static function defaults(): array
    {
        return ['columns' => '3', 'layout' => 'grid'];
    }

    public static function aiHints(): ?string
    {
        return 'images is a list of paths on the public disk or absolute https URLs.';
    }

    public static function schema(): array
    {
        return [
            AssetPicker::make('images')
                ->label(__('Images'))
                ->multiple(),
            ToggleButtons::make('columns')->label(__('Columns'))->options(['2' => '2', '3' => '3', '4' => '4'])->default('3')->inline()->grouped(),
            ToggleButtons::make('layout')->label(__('Layout'))->options(['grid' => __('Even grid'), 'masonry' => __('Masonry')])->default('grid')->inline()->grouped(),
        ];
    }
}
