<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class StatBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'stat';
    }

    public static function label(): string
    {
        return __('Statistic');
    }

    public static function description(): string
    {
        return __('A big number that counts up when it scrolls into view.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-chart-bar';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function defaults(): array
    {
        return ['value' => '98', 'suffix' => '%', 'label' => 'Customer satisfaction', 'animate' => true];
    }

    public static function editables(): array
    {
        return [
            'value' => Editable::text()->placeholder('42'),
            'label' => Editable::text()->placeholder(__('Label')),
            'description' => Editable::text()->multiline()->placeholder(__('Optional context')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'value is the number only (e.g. "12,000" or "4.9"); put units in prefix/suffix (e.g. prefix "$", suffix "k+", "%"). Use 3–4 stats in a "grid".';
    }

    public static function schema(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('prefix')->label(__('Prefix'))->placeholder('$'),
                TextInput::make('value')->label(__('Number')),
                TextInput::make('suffix')->label(__('Suffix'))->placeholder('%'),
            ]),
            TextInput::make('label')->label(__('Label')),
            TextInput::make('description')->label(__('Context')),
            Toggle::make('animate')->label(__('Count up on scroll'))->default(true),
        ];
    }
}
