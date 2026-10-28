<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Blocks\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;

class ButtonBlock extends Block
{
    public const VARIANTS = ['primary' => 'Primary', 'secondary' => 'Secondary', 'outline' => 'Outline', 'ghost' => 'Ghost', 'link' => 'Text link'];

    public static function type(): string
    {
        return 'button';
    }

    public static function label(): string
    {
        return __('Button');
    }

    public static function description(): string
    {
        return __('A call to action that links to a page or URL.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-cursor-click';
    }

    public static function defaults(): array
    {
        return ['label' => 'Get started', 'variant' => 'primary', 'size' => 'md', 'link_type' => 'page'];
    }

    public static function editables(): array
    {
        return ['label' => Editable::text()->placeholder(__('Button label'))];
    }

    public static function aiHints(): ?string
    {
        return 'Link to an internal page with link_type "page" and link_page = the page id (preferred: it follows slug changes), or link_type "url" and link_url. Use at most one primary button per area.';
    }

    public static function schema(): array
    {
        return [
            TextInput::make('label')->label(__('Label'))->maxLength(60),
            ...Fields::link(),
            ToggleButtons::make('variant')->label(__('Style'))->options(self::VARIANTS)->default('primary')->inline(),
            Grid::make(2)->schema([
                ToggleButtons::make('size')->label(__('Size'))->options(['sm' => 'S', 'md' => 'M', 'lg' => 'L'])->default('md')->inline()->grouped(),
                Fields::icon('icon', 'Trailing icon')->placeholder(__('None')),
            ]),
        ];
    }
}
