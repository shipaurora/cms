<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class ParagraphBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'text';
    }

    public static function label(): string
    {
        return __('Paragraph');
    }

    public static function description(): string
    {
        return __('Plain text you type directly on the canvas.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-text-align-left';
    }

    public static function defaults(): array
    {
        return ['size' => 'base'];
    }

    public static function editables(): array
    {
        return ['body' => Editable::text()->multiline()->placeholder(__('Start typing…'))];
    }

    public static function aiHints(): ?string
    {
        return 'Short plain-text paragraphs. Use "lead" size for the first paragraph after a heading. For formatting, links or lists use "rich-text" instead.';
    }

    public static function schema(): array
    {
        return [
            Textarea::make('body')->label(__('Text'))->rows(6),
            ToggleButtons::make('size')->label(__('Size'))->options(['sm' => __('Small'), 'base' => __('Normal'), 'lead' => __('Lead')])->default('base')->inline()->grouped(),
            ToggleButtons::make('align')->label(__('Alignment'))->options(['left' => __('Left'), 'center' => __('Centre')])->default('left')->inline()->grouped(),
        ];
    }
}
