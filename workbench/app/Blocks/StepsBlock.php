<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class StepsBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'steps';
    }

    public static function label(): string
    {
        return __('Steps / timeline');
    }

    public static function description(): string
    {
        return __('A numbered process or a timeline of milestones.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-list-bullets';
    }

    public static function defaults(): array
    {
        return [
            'layout' => 'horizontal',
            'items' => [
                ['title' => 'Plan', 'body' => 'Map out the pages and the story you want to tell.'],
                ['title' => 'Compose', 'body' => 'Drag blocks onto the canvas and write in place.'],
                ['title' => 'Publish', 'body' => 'Schedule it, or press publish. That is it.'],
            ],
        ];
    }

    public static function itemEditables(): array
    {
        return [
            'items' => [
                'kicker' => Editable::text()->placeholder('2026'),
                'title' => Editable::text()->placeholder(__('Step title')),
                'body' => Editable::text()->multiline()->placeholder(__('Describe the step')),
            ],
        ];
    }

    public static function aiHints(): ?string
    {
        return 'items is a list of {kicker?, title, body}. kicker replaces the step number, e.g. a year for a timeline. "horizontal" suits 3–4 steps, "vertical" suits longer timelines.';
    }

    public static function schema(): array
    {
        return [
            ToggleButtons::make('layout')->label(__('Layout'))->options(['horizontal' => __('Side by side'), 'vertical' => __('Timeline')])->default('horizontal')->inline()->grouped(),
            Repeater::make('items')
                ->label(__('Steps'))
                ->schema([
                    TextInput::make('kicker')->label(__('Label instead of number'))->placeholder('e.g. 2026'),
                    TextInput::make('title')->required(),
                    Textarea::make('body')->rows(2),
                ])
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->collapsed()
                ->cloneable()
                ->addActionLabel(__('Add step')),
        ];
    }
}
