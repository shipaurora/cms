<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Contracts\Container;
use Filament\Forms\Components\ToggleButtons;

class ButtonGroupBlock extends Block implements Container
{
    public static function type(): string
    {
        return 'button-group';
    }

    public static function label(): string
    {
        return __('Button group');
    }

    public static function description(): string
    {
        return __('Buttons side by side. Drag buttons in.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-dots-three-circle';
    }

    public static function category(): string
    {
        return 'layout';
    }

    public static function slots(array $data): array
    {
        return ['buttons'];
    }

    public static function defaults(): array
    {
        return ['justify' => 'start'];
    }

    public static function aiHints(): ?string
    {
        return 'Put 2–3 "button" blocks in the "buttons" slot, e.g. a primary and a secondary/ghost button.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('justify')
                ->label(__('Alignment'))
                ->options(['start' => __('Left'), 'center' => __('Centre'), 'end' => __('Right'), 'between' => __('Spread')])
                ->default('start')
                ->inline()
                ->grouped(),
        ];
    }
}
