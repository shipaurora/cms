<?php

namespace Aurora\Forms\Fields;

/**
 * One choice from a dropdown.
 */
class Select extends Choice
{
    public static function type(): string
    {
        return 'select';
    }

    public static function label(): string
    {
        return __('Dropdown');
    }

    public static function icon(): string
    {
        return 'phosphor-caret-up-down';
    }
}
