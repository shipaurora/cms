<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Blocks\Fields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class CallToActionBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'cta';
    }

    public static function label(): string
    {
        return __('Call to action');
    }

    public static function description(): string
    {
        return __('A bold banner that asks the visitor to take the next step.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-rocket-launch';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function defaults(): array
    {
        return [
            'style' => 'gradient',
            'title' => 'Ready to see it in action?',
            'text' => 'Spin up your first page in minutes. No credit card, no setup.',
            'button_label' => 'Start building',
            'button_type' => 'page',
        ];
    }

    public static function editables(): array
    {
        return [
            'title' => Editable::text()->placeholder(__('Title')),
            'text' => Editable::text()->multiline()->placeholder(__('Supporting text')),
            'button_label' => Editable::text()->placeholder(__('Button')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'Place near the end of a page, typically on its own in a white or muted band.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('style')->label(__('Style'))->options(['gradient' => __('Aurora'), 'brand' => __('Brand'), 'dark' => __('Dark'), 'light' => __('Light')])->default('gradient')->inline()->grouped(),
            TextInput::make('title')->label(__('Title')),
            Textarea::make('text')->label(__('Text'))->rows(2),
            Section::make(__('Button'))->compact()->schema([
                TextInput::make('button_label')->label(__('Label')),
                ...Fields::link('button', 'Links to'),
            ]),
        ];
    }
}
