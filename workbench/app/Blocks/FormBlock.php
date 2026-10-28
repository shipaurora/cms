<?php

namespace Workbench\App\Blocks;

use Aurora\Blocks\Block;
use Aurora\Models\Form;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;

class FormBlock extends Block
{
    public static function type(): string
    {
        return 'form';
    }

    public static function label(): string
    {
        return __('Form');
    }

    public static function description(): string
    {
        return __('A contact or sign-up form from the Forms section. Submissions land in the CMS and your inbox.');
    }

    public static function icon(): ?string
    {
        return 'phosphor-clipboard-text';
    }

    public static function category(): string
    {
        return 'dynamic';
    }

    public static function defaults(): array
    {
        return ['style' => 'card'];
    }

    public static function aiHints(): ?string
    {
        return 'form_id is the id of a form (see list_forms). Place it in a column next to a heading and some text, or alone in a narrow band.';
    }

    public static function schema(): array
    {
        return [
            Select::make('form_id')
                ->label(__('Form'))
                ->options(fn (): array => Form::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->helperText(__('Create and edit forms under Content → Forms.')),
            ToggleButtons::make('style')
                ->label(__('Style'))
                ->options(['card' => __('In a card'), 'plain' => __('Plain')])
                ->default('card')
                ->inline()
                ->grouped(),
        ];
    }
}
