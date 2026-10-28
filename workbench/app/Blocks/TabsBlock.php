<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;

class TabsBlock extends Block
{
    public static function type(): string
    {
        return 'tabs';
    }

    public static function label(): string
    {
        return __('Tabs');
    }

    public static function description(): string
    {
        return __('Switch between panels of content without leaving the page.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-folder';
    }

    public static function defaults(): array
    {
        return [
            'style' => 'pills',
            'items' => [
                ['label' => 'Design', 'title' => 'Design in context', 'body' => 'Shape every page visually, with real content and your real styles.'],
                ['label' => 'Collaborate', 'title' => 'Work as a team', 'body' => 'Drafts, reviews and revisions keep everyone in sync.'],
                ['label' => 'Automate', 'title' => 'Let AI do the heavy lifting', 'body' => 'Ask an assistant to draft a page and refine it together.'],
            ],
        ];
    }

    public static function itemEditables(): array
    {
        return [
            'items' => [
                'title' => Editable::text()->placeholder(__('Panel title')),
                'body' => Editable::text()->multiline()->placeholder(__('Panel text')),
            ],
        ];
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('style')->label(__('Style'))->options(['pills' => __('Pills'), 'underline' => __('Underline')])->default('pills')->inline()->grouped(),
            Repeater::make('items')
                ->label(__('Tabs'))
                ->schema([
                    TextInput::make('label')->required(),
                    TextInput::make('title'),
                    Textarea::make('body')->rows(3),
                ])
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->collapsible()
                ->collapsed()
                ->cloneable()
                ->addActionLabel(__('Add tab')),
        ];
    }
}
