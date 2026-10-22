<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Contracts\Container;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class BandBlock extends Block implements Container
{
    use HasPreviewImage;

    public const BACKGROUNDS = [
        'white' => 'White',
        'muted' => 'Soft grey',
        'brand-soft' => 'Brand tint',
        'brand' => 'Brand',
        'dark' => 'Dark',
        'aurora' => 'Aurora gradient',
        'image' => 'Image',
    ];

    public const PADDINGS = ['none' => 'None', 'sm' => 'S', 'md' => 'M', 'lg' => 'L', 'xl' => 'XL'];

    public const WIDTHS = ['narrow' => 'Narrow', 'default' => 'Default', 'wide' => 'Wide', 'full' => 'Edge to edge'];

    public static function type(): string
    {
        return 'band';
    }

    public static function label(): string
    {
        return __('Section band');
    }

    public static function description(): string
    {
        return __('Full-width band with a background. Holds any blocks, including columns.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-stack';
    }

    public static function category(): string
    {
        return 'layout';
    }

    public static function fileFields(): array
    {
        return [];
    }

    public static function slots(array $data): array
    {
        return ['content'];
    }

    public static function defaults(): array
    {
        return ['background' => 'white', 'padding' => 'lg', 'width' => 'default'];
    }

    public static function aiHints(): ?string
    {
        return 'The backbone of a page: wrap each horizontal part of the page (hero area aside) in a band. Alternate backgrounds (white, muted, brand-soft) between consecutive bands for rhythm. Put a "section" (columns) or "grid" inside for multi-column layouts. Dark/brand/aurora/image backgrounds automatically switch text to light.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('background')
                ->label(__('Background'))
                ->options(self::BACKGROUNDS)
                ->default('white')
                ->inline()
                ->live(),
            Section::make(__('Background image'))
                ->compact()
                ->visible(fn (Get $get): bool => $get('background') === 'image')
                ->schema([
                    AssetPicker::make('image')->hiddenLabel(),
                    Slider::make('overlay')->label(__('Darken'))->range(0, 90)->step(10)->default(50),
                ]),
            ToggleButtons::make('padding')->label(__('Vertical space'))->options(self::PADDINGS)->default('lg')->inline()->grouped(),
            ToggleButtons::make('width')->label(__('Content width'))->options(self::WIDTHS)->default('default')->inline()->grouped(),
        ];
    }
}
