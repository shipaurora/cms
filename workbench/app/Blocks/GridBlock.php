<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Contracts\Container;
use Filament\Forms\Components\ToggleButtons;

class GridBlock extends Block implements Container
{
    public static function type(): string
    {
        return 'grid';
    }

    public static function label(): string
    {
        return __('Responsive grid');
    }

    public static function description(): string
    {
        return __('Cards, features or stats that flow into 1–6 columns and wrap on small screens.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-squares-four';
    }

    public static function category(): string
    {
        return 'layout';
    }

    public static function slots(array $data): array
    {
        return ['items'];
    }

    public static function defaults(): array
    {
        return ['columns' => '3', 'gap' => 'md'];
    }

    public static function aiHints(): ?string
    {
        return 'Use for repeated items of the same kind (card, feature, stat, pricing, team-member, testimonial). Children all go into the single "items" slot and wrap automatically; unlike "section", you do not need one slot per column. Pick columns = number of items when it is 2–4, otherwise 3.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('columns')
                ->label(__('Columns on desktop'))
                ->options(['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'])
                ->default('3')
                ->inline()
                ->grouped(),
            ToggleButtons::make('gap')
                ->label(__('Space between'))
                ->options(['sm' => 'S', 'md' => 'M', 'lg' => 'L'])
                ->default('md')
                ->inline()
                ->grouped(),
            ToggleButtons::make('align')
                ->label(__('Vertical alignment'))
                ->options(['stretch' => __('Equal height'), 'start' => __('Top'), 'center' => __('Middle')])
                ->default('stretch')
                ->inline()
                ->grouped(),
        ];
    }
}
