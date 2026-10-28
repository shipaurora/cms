<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Blocks\Editable;
use Aurora\Filament\Forms\Components\AssetPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;

class TestimonialBlock extends Block
{
    public static function type(): string
    {
        return 'testimonial';
    }

    public static function label(): string
    {
        return __('Testimonial');
    }

    public static function description(): string
    {
        return __('A customer quote with name, role, photo and rating.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-chat-text';
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
            'quote' => 'We rebuilt our entire site in a week. Our editors actually enjoy publishing now.',
            'name' => 'Ingrid Solberg',
            'role' => 'Head of Marketing, Fjordline',
            'rating' => '5',
            'style' => 'card',
        ];
    }

    public static function editables(): array
    {
        return [
            'quote' => Editable::text()->multiline()->placeholder(__('What did they say?')),
            'name' => Editable::text()->placeholder(__('Name')),
            'role' => Editable::text()->placeholder(__('Role, company')),
        ];
    }

    public static function aiHints(): ?string
    {
        return 'style "large" is a single centred statement quote; "card" works best in a grid of 2–3.';
    }

    public static function schema(): array
    {
        return [
            Textarea::make('quote')->label(__('Quote'))->rows(4),
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('Name')),
                TextInput::make('role')->label(__('Role, company')),
            ]),
            AssetPicker::make('avatar')->label(__('Photo')),
            ToggleButtons::make('rating')->label(__('Stars'))->options(['0' => __('None'), '3' => '3', '4' => '4', '5' => '5'])->default('5')->inline()->grouped(),
            ToggleButtons::make('style')->label(__('Style'))->options(['card' => __('Card'), 'large' => __('Large statement')])->default('card')->inline()->grouped(),
        ];
    }
}
