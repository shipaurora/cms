<?php

namespace Aurora\Forms\Fields;

/**
 * A phone number: digits, spaces and + ( ) . -.
 */
class Phone extends Text
{
    public static function type(): string
    {
        return 'phone';
    }

    public static function label(): string
    {
        return __('Phone');
    }

    public static function icon(): string
    {
        return 'phosphor-phone';
    }

    public static function rules(array $field): array
    {
        return [$field['required'] ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\\s.-]+$/'];
    }
}
