<?php

namespace Aurora\Forms\Fields;

/**
 * A message of several lines.
 */
class Textarea extends Text
{
    public static function type(): string
    {
        return 'textarea';
    }

    public static function label(): string
    {
        return __('Long text');
    }

    public static function icon(): string
    {
        return 'phosphor-text-align-left';
    }

    public static function rules(array $field): array
    {
        return [$field['required'] ? 'required' : 'nullable', 'string', 'max:5000'];
    }
}
