<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;

class AlertBlock extends Block
{
    public const VARIANTS = ['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'danger' => 'Danger', 'brand' => 'Brand'];

    public static function type(): string
    {
        return 'alert';
    }

    public static function label(): string
    {
        return __('Callout');
    }

    public static function description(): string
    {
        return __('Draw attention to a note, tip, warning or announcement.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-megaphone';
    }

    public static function defaults(): array
    {
        return ['variant' => 'info', 'title' => 'Good to know', 'body' => 'Callouts highlight information the reader should not miss.'];
    }

    public static function editables(): array
    {
        return [
            'title' => Editable::text()->placeholder(__('Title')),
            'body' => Editable::text()->multiline()->placeholder(__('Message')),
        ];
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('variant')->label(__('Type'))->options(self::VARIANTS)->colors(['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'brand' => 'primary'])->default('info')->inline(),
            TextInput::make('title')->label(__('Title')),
            Textarea::make('body')->label(__('Message'))->rows(3),
        ];
    }
}
