<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Blocks\Fields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;

class FeatureBlock extends Block
{
    public static function type(): string
    {
        return 'feature';
    }

    public static function label(): string
    {
        return __('Feature');
    }

    public static function description(): string
    {
        return __('An icon with a title and a line of text.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-lightbulb';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function defaults(): array
    {
        return ['icon' => 'bolt', 'title' => 'Lightning fast', 'body' => 'Pages render in milliseconds, so visitors never wait.', 'style' => 'soft', 'tone' => 'brand'];
    }

    public static function editables(): array
    {
        return [
            'title' => Editable::text()->placeholder(__('Feature title')),
            'body' => Editable::text()->multiline()->placeholder(__('Describe the benefit')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'Place 3, 4 or 6 features in a "grid". icon must be one of the listed icon names.';
    }

    public static function schema(): array
    {
        return [
            Fields::icon(),
            TextInput::make('title')->label(__('Title')),
            Textarea::make('body')->label(__('Text'))->rows(3),
            ToggleButtons::make('style')->label(__('Icon style'))->options(['soft' => __('Soft'), 'solid' => __('Solid'), 'outline' => __('Outline'), 'card' => __('In a card')])->default('soft')->inline()->grouped(),
            Fields::tone(),
        ];
    }
}
