<?php

namespace Aurora\Forms\Fields;

/**
 * One choice, all of them shown.
 */
class Radio extends Choice
{
    public static function type(): string
    {
        return 'radio';
    }

    public static function label(): string
    {
        return __('Single choice');
    }

    public static function icon(): string
    {
        return 'phosphor-stop-circle';
    }
}
