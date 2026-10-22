<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Blocks\Fields;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class HeroBlock extends Block
{
    use HasPreviewImage;

    public const LAYOUTS = ['centered' => 'Centred', 'split' => 'Text + image', 'cover' => 'Full-bleed image'];

    public static function type(): string
    {
        return 'hero';
    }

    public static function label(): string
    {
        return __('Hero');
    }

    public static function description(): string
    {
        return __('The big opening statement: headline, intro and calls to action.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-sparkle';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function fileFields(): array
    {
        return [];
    }

    public static function defaults(): array
    {
        return [
            'layout' => 'centered',
            'badge' => 'New',
            'badge_text' => 'Introducing our latest release',
            'headline' => 'Build pages people *love* to use',
            'subheadline' => 'Compose beautiful, fast pages visually, then let your team and your AI assistant take it from there.',
            'primary_label' => 'Get started',
            'primary_type' => 'page',
            'secondary_label' => 'Learn more',
            'secondary_type' => 'page',
        ];
    }

    public static function editables(): array
    {
        return [
            'badge' => Editable::text()->placeholder(__('Badge')),
            'badge_text' => Editable::text()->placeholder(__('Announcement')),
            'headline' => Editable::text()->multiline()->placeholder(__('Write a headline')),
            'subheadline' => Editable::text()->multiline()->placeholder(__('Add an introduction')),
            'primary_label' => Editable::text()->placeholder(__('Primary action')),
            'secondary_label' => Editable::text()->placeholder(__('Secondary action')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'Use once, as the first block of a page (not inside a band). The headline is the page h1, 4–9 words; wrap one key word in *asterisks* to highlight it. layout "split" or "cover" need an image. Leave badge empty to hide the announcement pill.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('layout')->label(__('Layout'))->options(self::LAYOUTS)->default('centered')->inline()->grouped()->live(),
            Section::make(__('Announcement'))
                ->compact()
                ->collapsible()
                ->columns(3)
                ->schema([
                    TextInput::make('badge')->label(__('Badge'))->maxLength(20),
                    TextInput::make('badge_text')->label(__('Text'))->columnSpan(2),
                ]),
            Textarea::make('headline')->label(__('Headline'))->rows(2)->helperText(__('Wrap a word in *asterisks* to highlight it.')),
            Textarea::make('subheadline')->label(__('Introduction'))->rows(3),
            AssetPicker::make('image')
                ->label(__('Image'))
                ->visible(fn (Get $get): bool => $get('layout') !== 'centered'),
            Section::make(__('Primary action'))->compact()->collapsible()->schema([
                TextInput::make('primary_label')->label(__('Label')),
                ...Fields::link('primary', 'Links to'),
            ]),
            Section::make(__('Secondary action'))->compact()->collapsible()->collapsed()->schema([
                TextInput::make('secondary_label')->label(__('Label')),
                ...Fields::link('secondary', 'Links to'),
            ]),
        ];
    }
}
