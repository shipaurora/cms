<?php

namespace Aurora\Forms\Fields;

/**
 * An email address; the first one in a submission is where a reply goes.
 */
class Email extends Text
{
    public static function type(): string
    {
        return 'email';
    }

    public static function label(): string
    {
        return __('Email');
    }

    public static function icon(): string
    {
        return 'phosphor-envelope-simple';
    }

    public static function rules(array $field): array
    {
        return [$field['required'] ? 'required' : 'nullable', 'email:rfc', 'max:255'];
    }
}
