<?php

namespace Aurora\Forms\Fields;

use Aurora\Forms\FieldType;
use Filament\Forms\Components\TextInput;

/**
 * A line of text, and the base of the other typed-in fields (email, phone, long text, date):
 * they all take a placeholder.
 */
class Text extends FieldType
{
    public static function type(): string
    {
        return 'text';
    }

    public static function label(): string
    {
        return __('Short text');
    }

    public static function icon(): string
    {
        return 'phosphor-pencil-simple';
    }

    public static function settings(): array
    {
        return [
            TextInput::make('placeholder')->maxLength(255)->withAi(),
        ];
    }
}
