<?php

namespace Aurora\Forms\Fields;

/**
 * A date.
 */
class Date extends Text
{
    public static function type(): string
    {
        return 'date';
    }

    public static function label(): string
    {
        return __('Date');
    }

    public static function icon(): string
    {
        return 'phosphor-calendar-dots';
    }

    public static function rules(array $field): array
    {
        return [$field['required'] ? 'required' : 'nullable', 'date'];
    }
}
