<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Blocks\Fields;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Workbench\App\Blocks\Concerns\HasPreviewImage;

class PricingBlock extends Block
{
    use HasPreviewImage;

    public static function type(): string
    {
        return 'pricing';
    }

    public static function label(): string
    {
        return __('Pricing plan');
    }

    public static function description(): string
    {
        return __('A plan with price, feature list and a button. Put several in a grid.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-currency-dollar';
    }

    public static function category(): string
    {
        return 'marketing';
    }

    public static function defaults(): array
    {
        return [
            'name' => 'Team',
            'price' => '49',
            'currency' => '$',
            'period' => '/month',
            'description' => 'For growing teams that ship every week.',
            'features' => [['text' => 'Unlimited pages'], ['text' => 'Visual editor'], ['text' => 'AI assistant via MCP']],
            'button_label' => 'Choose plan',
            'button_type' => 'page',
            'featured' => false,
        ];
    }

    public static function editables(): array
    {
        return [
            'name' => Editable::text()->placeholder(__('Plan name')),
            'price' => Editable::text()->placeholder('0'),
            'period' => Editable::text()->placeholder('/month'),
            'description' => Editable::text()->multiline()->placeholder(__('Who is it for?')),
            'badge' => Editable::text()->placeholder(__('Most popular')),
            'button_label' => Editable::text()->placeholder(__('Button')),
        ];
    }

    public static function itemEditables(): array
    {
        return ['features' => ['text' => Editable::text()->placeholder(__('Feature'))]];
    }

    public static function aiHints(): ?string
    {
        return 'Put 2–4 plans in a "grid" with columns equal to the number of plans and align "stretch". Mark exactly one plan featured with a badge like "Most popular". features is a list of {text}.';
    }

    public static function schema(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('Plan')),
                TextInput::make('badge')->label(__('Badge'))->placeholder(__('Most popular'))->visible(fn (Get $get): bool => (bool) $get('featured')),
            ]),
            Toggle::make('featured')->label(__('Highlight this plan'))->live(),
            Grid::make(3)->schema([
                TextInput::make('currency')->label(__('Currency'))->default('$'),
                TextInput::make('price')->label(__('Price')),
                TextInput::make('period')->label(__('Period')),
            ]),
            Textarea::make('description')->label(__('Description'))->rows(2),
            Repeater::make('features')
                ->label(__('Included'))
                ->schema([TextInput::make('text')->hiddenLabel()->required()])
                ->itemLabel(fn (array $state): ?string => $state['text'] ?? null)
                ->collapsed()
                ->reorderableWithDragAndDrop()
                ->addActionLabel(__('Add feature')),
            Section::make(__('Button'))->compact()->schema([
                TextInput::make('button_label')->label(__('Label')),
                ...Fields::link('button', 'Links to'),
            ]),
        ];
    }
}
