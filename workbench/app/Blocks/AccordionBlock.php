<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class AccordionBlock extends Block
{
    public static function type(): string
    {
        return 'accordion';
    }

    public static function label(): string
    {
        return __('Accordion / FAQ');
    }

    public static function description(): string
    {
        return __('Questions that expand to answers. Edit questions right on the page.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-rows';
    }

    public static function defaults(): array
    {
        return [
            'items' => [
                ['question' => 'What is included?', 'answer' => 'Everything you need to get going, with no hidden extras.'],
                ['question' => 'Can I cancel at any time?', 'answer' => 'Yes. You can cancel with one click and keep access until the end of the period.'],
                ['question' => 'Do you offer support?', 'answer' => 'Our team answers within one working day, often much faster.'],
            ],
            'first_open' => true,
        ];
    }

    public static function itemEditables(): array
    {
        return [
            'items' => [
                'question' => Editable::text()->placeholder(__('Question')),
                'answer' => Editable::text()->multiline()->placeholder(__('Answer')),
            ],
        ];
    }

    public static function aiHints(): ?string
    {
        return 'items is a list of {question, answer}; plain text answers (line breaks allowed). 3–8 items works best. Also emits FAQPage structured data for search engines.';
    }

    public static function schema(): array
    {
        return [
            Repeater::make('items')
                ->label(__('Items'))
                ->schema([
                    TextInput::make('question')->required(),
                    Textarea::make('answer')->rows(3),
                ])
                ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                ->collapsible()
                ->collapsed()
                ->cloneable()
                ->reorderableWithButtons()
                ->addActionLabel(__('Add question'))
                ->defaultItems(1),
            Toggle::make('first_open')->label(__('Open the first item')),
            Toggle::make('faq_schema')->label(__('Add FAQ structured data (SEO)'))->default(true),
        ];
    }
}
