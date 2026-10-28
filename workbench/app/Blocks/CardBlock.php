<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Blocks\Fields;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;

class CardBlock extends Block
{
    public static function type(): string
    {
        return 'card';
    }

    public static function label(): string
    {
        return __('Card');
    }

    public static function description(): string
    {
        return __('Image, title, text and a link in a tidy box. Great in a grid.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-identification-card';
    }

    public static function fileFields(): array
    {
        return [];
    }

    public static function defaults(): array
    {
        return ['style' => 'elevated', 'title' => 'Card title', 'body' => 'A short description that tells the reader why this matters.', 'link_label' => 'Read more', 'link_type' => 'none'];
    }

    public static function editables(): array
    {
        return [
            'badge' => Editable::text()->placeholder(__('Badge')),
            'title' => Editable::text()->placeholder(__('Title')),
            'body' => Editable::text()->multiline()->placeholder(__('Description')),
            'link_label' => Editable::text()->placeholder(__('Link label')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'Normally placed inside a "grid". The whole card becomes clickable when it has a link.';
    }

    public static function schema(): array
    {
        return [
            AssetPicker::make('image')->label(__('Image')),
            Grid::make(3)->schema([
                TextInput::make('badge')->label(__('Badge')),
                TextInput::make('title')->label(__('Title'))->columnSpan(2),
            ]),
            Textarea::make('body')->label(__('Text'))->rows(3),
            TextInput::make('link_label')->label(__('Link label')),
            ...Fields::link(),
            ToggleButtons::make('style')->label(__('Style'))->options(['elevated' => __('Elevated'), 'bordered' => __('Bordered'), 'ghost' => __('Plain'), 'glass' => __('Glass')])->default('elevated')->inline()->grouped(),
        ];
    }
}
